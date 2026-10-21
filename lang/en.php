<?php

declare(strict_types=1);

return [
    'app.name' => 'Nordvarr',
    'app.tagline' => 'What we run, what depends on what, and what changed',

    'nav.main' => 'Main navigation',
    'nav.overview' => 'Overview',
    'nav.inventory' => 'Inventory',
    'nav.topology' => 'Topology',
    'nav.alerts' => 'Alerts',
    'nav.settings' => 'Settings',

    'user.logged_in_as' => 'Signed in: {email}',
    'user.logout' => 'Sign out',

    'lang.label' => 'Language',
    'lang.cs' => 'Čeština',
    'lang.en' => 'English',
    'lang.switch' => 'Switch',

    'login.title' => 'Sign in',
    'login.email' => 'Email',
    'login.password' => 'Password',
    'login.submit' => 'Sign in',
    'login.invalid' => 'Incorrect email or password.',
    'login.throttled' => 'Too many failed attempts. Please try again later.',
    'login.logged_out' => 'You have been signed out.',

    'dashboard.title' => 'Overview',
    'dashboard.welcome' => 'Welcome to Nordvarr.',
    'dashboard.placeholder' => 'The overview of states, changes and upcoming expirations arrives in phase F2.',

    'placeholder.text' => 'This section will be added in phase {phase}.',

    'migrations.done' => 'The database was updated (migrations {versions}). Backup before migration: {backup}',

    'error.title' => 'Error',
    'error.csrf' => 'The form has expired. Reload the page and try again.',
    'error.not_found' => 'Page not found.',
    'error.method_not_allowed' => 'This method is not allowed for this address.',
    'error.bearer_rejected' => 'The web interface does not accept API tokens. Use the /api/v1/ endpoints.',
    'error.back_home' => 'Back to overview',

    'footer.version' => 'Version {version}',

    'install.title' => 'Nordvarr installation',
    'install.locked' => 'Nordvarr is already installed (config.php exists). The installer is locked.',
    'install.go_login' => 'Go to sign in',
    'install.requirements' => 'Requirements',
    'install.req_php' => 'PHP {required} or newer (found {found})',
    'install.req_ext' => 'PHP extension {name}',
    'install.req_writable' => 'Write access to the application directory (for config.php)',
    'install.req_ok' => 'OK',
    'install.req_fail' => 'missing',
    'install.req_blocking' => 'Some requirements are not met. The installation cannot be completed.',
    'install.data_dir' => 'Data directory',
    'install.data_dir_help' => 'Absolute path outside the web root. It will hold the database, logs, locks and backups.',
    'install.admin_email' => 'Administrator email',
    'install.admin_password' => 'Administrator password',
    'install.admin_password_help' => 'At least {min} characters.',
    'install.admin_password_confirm' => 'Repeat password',
    'install.timezone' => 'Time zone',
    'install.submit' => 'Install',
    'install.err_data_dir' => 'The data directory must be an absolute path.',
    'install.err_data_dir_create' => 'The data directory cannot be created or is not writable.',
    'install.err_data_dir_used' => 'The data directory already contains a Nordvarr database. Choose an empty directory.',
    'install.err_data_dir_in_app' => 'The data directory must not be inside the application directory (an update would overwrite it).',
    'install.err_email' => 'Enter a valid email.',
    'install.err_password' => 'The password must have at least {min} characters.',
    'install.err_password_confirm' => 'The passwords do not match.',
    'install.err_timezone' => 'Unknown time zone.',
    'install.err_config_write' => 'The config.php file cannot be created.',
    'install.done_title' => 'Installation complete',
    'install.done_text' => 'Nordvarr is installed. The installer is now locked.',
    'install.cron' => 'Add this line to the web user\'s crontab (the worker runs every minute):',
    'install.warn_web_reachable' => 'Warning: the data directory is reachable from the web! Move it outside the web root or deny access in the server configuration.',
    'install.warn_in_docroot' => 'The data directory is inside the web root. It is protected by .htaccess, but moving it outside the web root is safer.',
    'install.data_dir_ok' => 'The data directory is outside the web root.',
];
