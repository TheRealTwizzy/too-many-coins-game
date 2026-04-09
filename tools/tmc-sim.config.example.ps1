$config = @{
    TmcSshHost = 'srv1529799.hstgr.cloud'
    TmcSshPort = 22
    TmcSshUser = 'root'
    TmcSshKeyPath = 'C:\Users\YOUR_USER\.ssh\id_ed25519'
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