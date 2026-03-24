// PQCServer — MongoDB Indexes Setup
// Run with: mongosh pqcserver mongo_indexes.js

// ── messages: TTL + lookup ────────────────────────────────────────────────────
db.messages.createIndex(
  { "expires_at": 1 },
  { expireAfterSeconds: 0, name: "ttl_expires" }
);
db.messages.createIndex({ "recipient": 1 }, { sparse: true, name: "idx_recipient" });
db.messages.createIndex({ "read": 1 },      { name: "idx_read" });

// ── users ─────────────────────────────────────────────────────────────────────
db.users.createIndex(
  { "email": 1 },
  { unique: true, sparse: true, name: "idx_email_unique" }
);

// ── sessions: TTL ─────────────────────────────────────────────────────────────
db.sessions.createIndex(
  { "expires_at": 1 },
  { expireAfterSeconds: 0, name: "ttl_sessions" }
);

// ── upload_chunks: TTL (auto-delete incomplete uploads after 2h) ──────────────
db.upload_chunks.createIndex(
  { "expires_at": 1 },
  { expireAfterSeconds: 0, name: "ttl_upload_chunks" }
);
db.upload_chunks.createIndex(
  { "upload_id": 1, "chunk_index": 1 },
  { name: "idx_upload_chunk" }
);

// ── GridFS encrypted_files: TTL on metadata.expires_at ───────────────────────
db.getCollection("encrypted_files.files").createIndex(
  { "metadata.expires_at": 1 },
  { expireAfterSeconds: 0, name: "ttl_gridfs_files", sparse: true }
);

print("✓ All indexes created successfully");
print("Collections: messages, users, sessions, upload_chunks, encrypted_files.*");

// ── timestamps — no TTL (permanent records) ────────────────────────────────
db.timestamps.createIndex({ "signer":        1 }, { sparse: true, name: "idx_ts_signer" });
db.timestamps.createIndex({ "document_hash": 1 }, { name: "idx_ts_hash" });
db.timestamps.createIndex({ "created_at":    1 }, { name: "idx_ts_created" });

print("  timestamps: idx_ts_signer, idx_ts_hash, idx_ts_created");

// ── timestamps — permanent (no TTL) ───────────────────────────────────────
db.timestamps.createIndex({ "hash_sha256":     1 }, { name: "idx_ts_hash" });
db.timestamps.createIndex({ "signer_username": 1 }, { sparse: true, name: "idx_ts_signer" });
db.timestamps.createIndex({ "created_at":      -1 }, { name: "idx_ts_date" });

print("  timestamps: idx_ts_hash, idx_ts_signer, idx_ts_date");

// ── notary_receipts ────────────────────────────────────────────────────────
db.notary_receipts.createIndex(
    { "signer_username": 1 },
    { sparse: true, name: "idx_notary_signer" }
);
db.notary_receipts.createIndex(
    { "hash_sha256": 1 },
    { name: "idx_notary_hash256" }
);
db.notary_receipts.createIndex(
    { "issued_at": -1 },
    { name: "idx_notary_date" }
);

print("  notary_receipts: idx_notary_signer, idx_notary_hash256, idx_notary_date");

// ── notary_receipts ────────────────────────────────────────────────────────
db.notary_receipts.createIndex(
    { "signer_username": 1 },
    { name: "idx_notary_signer" }
);
db.notary_receipts.createIndex(
    { "hash_sha256": 1 },
    { name: "idx_notary_hash" }
);
db.notary_receipts.createIndex(
    { "issued_at": -1 },
    { name: "idx_notary_issued" }
);

print("  notary_receipts: idx_notary_signer, idx_notary_hash, idx_notary_issued");

// ── vault_files ────────────────────────────────────────────────────────────
db.vault_files.createIndex(
    { "owner": 1, "created_at": -1 },
    { name: "idx_vault_owner_date" }
);
db.vault_files.createIndex(
    { "owner": 1, "filename": 1 },
    { name: "idx_vault_owner_filename" }
);
db.vault_files.createIndex(
    { "owner": 1, "tags": 1 },
    { sparse: true, name: "idx_vault_tags" }
);
db.vault_files.createIndex(
    { "shared_with": 1 },
    { sparse: true, name: "idx_vault_shared" }
);

print("  vault_files: idx_vault_owner_date, idx_vault_owner_filename, idx_vault_tags, idx_vault_shared");

// ── vault_files ────────────────────────────────────────────────────────────
db.vault_files.createIndex(
    { "owner": 1, "created_at": -1 },
    { name: "idx_vault_owner_date" }
);
db.vault_files.createIndex(
    { "owner": 1, "filename": 1 },
    { name: "idx_vault_owner_filename" }
);
db.vault_files.createIndex(
    { "owner": 1, "tags": 1 },
    { sparse: true, name: "idx_vault_tags" }
);
db.vault_files.createIndex(
    { "shared_with": 1 },
    { sparse: true, name: "idx_vault_shared" }
);
db.vault_files.createIndex(
    { "file_id": 1 },
    { name: "idx_vault_file_id" }
);

print("  vault_files: idx_vault_owner_date, idx_vault_owner_filename, idx_vault_tags, idx_vault_shared, idx_vault_file_id");
