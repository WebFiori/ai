<?php

/**
 * This file is licensed under MIT License.
 *
 * Copyright (c) 2026-present WebFiori Framework.
 *
 * For more information on the license, please visit:
 * https://github.com/WebFiori/ai/blob/main/LICENSE
 */
namespace WebFiori\Ai\Embedding;

/**
 * Constants for {@see SqlServerVectorStore} option keys.
 *
 * These are the recognized keys in the $options array passed to
 * SqlServerVectorStore's constructor. Using these constants instead of raw
 * strings provides IDE discoverability and prevents typo-related bugs.
 *
 * The store-level keys (CONNECTION_INFO, TABLE, AUTO_CREATE) are owned and
 * validated by the store. The CONN_* constants are a convenience subset of the
 * most common Microsoft `sqlsrv` connection-info keys; their values are the
 * exact driver strings. This subset is intentionally non-exhaustive — any other
 * `sqlsrv` connection option may be passed through CONNECTION_INFO as a raw
 * string key, and a missing constant does not imply the option is unsupported.
 *
 * Usage:
 * ```php
 * $store = new SqlServerVectorStore('tcp:localhost,1433', [
 *     SqlServerOption::CONNECTION_INFO => [
 *         SqlServerOption::CONN_DATABASE => 'app_db',
 *         SqlServerOption::CONN_UID      => 'app_user',
 *         SqlServerOption::CONN_PWD      => 'secret',
 *         SqlServerOption::CONN_ENCRYPT  => true,
 *     ],
 *     SqlServerOption::TABLE       => 'vectors',
 *     SqlServerOption::AUTO_CREATE => false,
 * ]);
 * ```
 *
 * @author Ibrahim
 */
final class SqlServerOption {
    /**
     * Whether the store should issue DDL to create its table/index if missing.
     *
     * Defaults to false. The recommended deployment uses a dedicated,
     * least-privilege SQL Server account that typically lacks DDL rights, so
     * unconditional table creation would fail. When true, the store issues
     * idempotent DDL (guarded by an existence check). For locked-down
     * deployments, leave this false and provision the table out-of-band using
     * {@see SqlServerVectorStore::getSchemaSql()}.
     */
    public const AUTO_CREATE = 'auto_create';

    // ─── Connection-info keys (convenience subset of sqlsrv keys) ──────────────
    // Values equal the exact Microsoft driver strings. Non-exhaustive: any other
    // sqlsrv connection option may be passed through CONNECTION_INFO verbatim.

    /**
     * The database name. Maps to the `sqlsrv` "Database" connection key.
     */
    public const CONN_DATABASE = 'Database';

    /**
     * Whether to encrypt the connection. Maps to the `sqlsrv` "Encrypt" key.
     */
    public const CONN_ENCRYPT = 'Encrypt';

    /**
     * Login timeout in seconds. Maps to the `sqlsrv` "LoginTimeout" key.
     */
    public const CONN_LOGIN_TIMEOUT = 'LoginTimeout';

    /**
     * The password. Maps to the `sqlsrv` "PWD" connection key.
     */
    public const CONN_PWD = 'PWD';

    /**
     * Whether to trust the server certificate without validation. Maps to the
     * `sqlsrv` "TrustServerCertificate" key.
     */
    public const CONN_TRUST_SERVER_CERTIFICATE = 'TrustServerCertificate';

    /**
     * The user ID. Maps to the `sqlsrv` "UID" connection key.
     */
    public const CONN_UID = 'UID';

    /**
     * The nested connection-info array passed verbatim to sqlsrv_connect().
     *
     * Its keys are Microsoft's `sqlsrv` connection options (see the CONN_*
     * constants for a common subset). Any key not covered by a constant may be
     * supplied as a raw string.
     */
    public const CONNECTION_INFO = 'connection_info';

    /**
     * The name of the table used to store vectors. Defaults to "vectors".
     */
    public const TABLE = 'table';

    /**
     * SqlServerOption is a constants holder and must not be instantiated.
     */
    private function __construct() {
    }
}
