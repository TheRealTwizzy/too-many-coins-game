$config = @{
    TmcSshHost = 'srv1529799.hstgr.cloud'
    TmcSshPort = 22
    TmcSshUser = 'root'
    # Required: full path to the private key used for SSH key auth.
    # TmcSshKeyPath is kept for backward compatibility, but TmcSshIdentityFile is preferred.
    TmcSshIdentityFile = 'C:\Users\YOUR_USER\.ssh\id_ed25519'
    TmcSshKeyPath = 'C:\Users\YOUR_USER\.ssh\id_ed25519'
    # Optional: explicit known_hosts file for strict host key verification in automation.
    # If omitted, OpenSSH default known_hosts resolution is used.
    # TmcSshKnownHostsPath = 'C:\Users\YOUR_USER\.ssh\known_hosts'
    # Optional: fail fast if SSH cannot connect/authenticate in this many seconds (default: 10).
    TmcSshConnectTimeoutSeconds = 10
    TmcRemoteDbHost = '127.0.0.1'
    TmcRemoteDbPort = 3306
    TmcDbName = 'too_many_coins'
    TmcDbUser = 'tmc_user'
    TmcDbPass = 'SET_LOCALLY_ONLY'
    TmcLocalForwardPort = 3307

    # --- Fresh-run simulation (local disposable DB) ---
    # These keys are used only by fresh-bootstrap / fresh-teardown / fresh-status steps.
    # The DB name MUST start with tmc_sim_, tmc_fresh_, or tmc_test_sim_.
    # The host MUST be 127.0.0.1, localhost, or ::1.
    TmcFreshDbHost = '127.0.0.1'
    TmcFreshDbPort = 3306
    TmcFreshDbName = 'tmc_sim_fresh'
    TmcFreshDbUser = 'root'
    TmcFreshDbPass = 'SET_LOCALLY_ONLY'
}

$config