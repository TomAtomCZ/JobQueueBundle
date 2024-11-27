<?php

namespace TomAtom\JobQueueBundle\Security;

final class JobQueuePermissions
{
    public const ROLE_ALL = 'ROLE_JQB_ALL'; // Main role with all permissions
    public const ROLE_JOBS = 'ROLE_JQB_JOBS'; // All permissions for jobs
    public const ROLE_COMMANDS = 'ROLE_JQB_COMMANDS'; // All permissions for commands
    public const ROLE_JOB_LIST = 'ROLE_JQB_JOB_LIST';
    public const ROLE_JOB_READ = 'ROLE_JQB_JOB_READ';
    public const ROLE_JOB_CREATE = 'ROLE_JQB_JOB_CREATE';
    public const ROLE_JOB_DELETE = 'ROLE_JQB_JOB_DELETE';
    public const ROLE_COMMAND_SCHEDULE = 'ROLE_JQB_COMMAND_SCHEDULE';
}
