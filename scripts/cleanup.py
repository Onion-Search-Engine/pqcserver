#!/usr/bin/env python3
"""
PQCServer — Maintenance & Stats Script
Cron: 0 3 * * * python3 /var/www/pqcserver/scripts/cleanup.py >> /var/log/pqcserver.log 2>&1
"""
import sys
from datetime import datetime, timezone
from pymongo import MongoClient
from bson import ObjectId

MONGO_URI = "mongodb://127.0.0.1:27017"
DB_NAME   = "pqcserver"

def get_db():
    client = MongoClient(MONGO_URI, serverSelectionTimeoutMS=5000)
    return client[DB_NAME]

def ensure_indexes(db):
    """Create/verify all required indexes."""
    # messages TTL
    db.messages.create_index("expires_at", expireAfterSeconds=0, name="ttl_expires")
    db.messages.create_index("recipient",  name="idx_recipient", sparse=True)
    db.messages.create_index("sender",     name="idx_sender",    sparse=True)
    # sessions TTL
    db.sessions.create_index("expires_at", expireAfterSeconds=0, name="ttl_sessions")
    # users
    db.users.create_index("email", unique=True, sparse=True, name="idx_email_unique")
    # GridFS TTL — orphan cleanup after 30 days
    db["encrypted_files.files"].create_index(
        "metadata.uploaded_at",
        expireAfterSeconds=30 * 24 * 3600,
        name="ttl_gridfs_files"
    )
    print("[indexes] All indexes verified.")

def cleanup_burned_messages(db):
    """Delete already-read burn-after-read messages + their GridFS files."""
    burned = list(db.messages.find({"burn_after_read": True, "read": True}, {"file_id": 1}))
    if not burned:
        return

    # Delete GridFS files
    bucket_files = db["encrypted_files.files"]
    bucket_chunks = db["encrypted_files.chunks"]
    deleted_files = 0
    for msg in burned:
        fid = msg.get("file_id")
        if fid:
            try:
                oid = ObjectId(fid)
                bucket_chunks.delete_many({"files_id": oid})
                bucket_files.delete_one({"_id": oid})
                deleted_files += 1
            except Exception:
                pass

    # Delete messages
    ids = [m["_id"] for m in burned]
    result = db.messages.delete_many({"_id": {"$in": ids}})
    print(f"[cleanup] Deleted {result.deleted_count} burned messages, {deleted_files} GridFS files.")

def cleanup_orphan_gridfs(db):
    """Delete GridFS files with no matching message (orphans)."""
    # Get all file_ids referenced by messages
    referenced = set(
        str(m["file_id"])
        for m in db.messages.find({"file_id": {"$ne": None}}, {"file_id": 1})
    )
    # Get all GridFS file IDs
    all_files = list(db["encrypted_files.files"].find({}, {"_id": 1}))
    orphans = [f["_id"] for f in all_files if str(f["_id"]) not in referenced]

    if orphans:
        db["encrypted_files.chunks"].delete_many({"files_id": {"$in": orphans}})
        db["encrypted_files.files"].delete_many({"_id": {"$in": orphans}})
        print(f"[cleanup] Deleted {len(orphans)} orphaned GridFS files.")

def print_stats(db):
    now = datetime.now(timezone.utc)
    print(f"\n{'='*52}")
    print(f"PQCServer Stats — {now.strftime('%Y-%m-%d %H:%M UTC')}")
    print(f"{'='*52}")

    # Messages
    print(f"\n[messages]")
    print(f"  Total:           {db.messages.count_documents({})}")
    print(f"  Unread:          {db.messages.count_documents({'read': False})}")
    print(f"  With file:       {db.messages.count_documents({'has_file': True})}")
    print(f"  Burn pending:    {db.messages.count_documents({'burn_after_read': True, 'read': False})}")

    # Users
    print(f"\n[users]")
    print(f"  Total:           {db.users.count_documents({})}")
    print(f"  With keys:       {db.users.count_documents({'has_keys': True})}")

    # Sessions
    print(f"\n[sessions]")
    print(f"  Active:          {db.sessions.count_documents({})}")

    # GridFS
    gridfs_count = db["encrypted_files.files"].count_documents({})
    pipeline = [{"$group": {"_id": None, "total": {"$sum": "$length"}}}]
    size_res  = list(db["encrypted_files.files"].aggregate(pipeline))
    total_mb  = (size_res[0]["total"] / 1024 / 1024) if size_res else 0
    print(f"\n[gridfs encrypted files]")
    print(f"  Files stored:    {gridfs_count}")
    print(f"  Total size:      {total_mb:.1f} MB")
    print(f"{'='*52}\n")

def main():
    print(f"[pqcserver] Maintenance — {datetime.now().isoformat()}")
    try:
        db = get_db()
        ensure_indexes(db)
        cleanup_burned_messages(db)
        cleanup_orphan_gridfs(db)
        print_stats(db)
        print("[pqcserver] Done.")
    except Exception as e:
        print(f"[pqcserver] ERROR: {e}", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
