-- Patches v7 (db/patch_v7_banimentos.php, db/patch_v7_eventos.php, db/patch_v7_logs.php).
--
-- v7_eventos: system_notices.user_id passa a aceitar NULL (aviso global). Em SQLite isso exigiria
-- recriar a tabela; por isso a 0001 já cria a coluna NULL-ável. Nada a fazer aqui.

-- v7_banimentos: motivo da revogação + índice do filtro "ativos/expirados/revogados"
ALTER TABLE bans ADD COLUMN revoke_reason TEXT NULL;
CREATE INDEX ix_bans_estado ON bans (revoked_at, expires_at);

-- v7_logs: filtro por ação na tela LOGS DO SISTEMA
CREATE INDEX ix_audit_action ON admin_audit_log ("action", created_at);
