<?php

namespace App\Console\Commands;

use App\Models\BackupRestoreRequest;
use App\Models\User;
use App\Services\Backup\RestoreWorkflow;
use Illuminate\Console\Command;
use Throwable;

class BackupRestoreRecord extends Command
{
    protected $signature = 'backup:restore-record {request} {action : test-start, test-pass, test-fail, start, complete or fail} {--actor= : Account ID of the authorized operator} {--evidence= : Restricted incident evidence ticket identifier}';

    protected $description = 'Audit an externally executed recovery; this command NEVER restores a database';

    public function handle(RestoreWorkflow $workflow): int
    {
        if (! in_array($this->argument('action'), RestoreWorkflow::OPERATOR_ACTIONS, true)) {
            $this->error('Invalid recording action.');

            return self::FAILURE;
        }
        try {
            $workflow->transition(BackupRestoreRequest::findOrFail($this->argument('request')), $this->argument('action'), User::findOrFail($this->option('actor')), $this->option('evidence'));
        } catch (Throwable) {
            $this->error('Recording denied: check authorization, workflow state and evidence identifier.');

            return self::FAILURE;
        }
        $this->info('External recovery activity recorded. No database restore was executed.');

        return self::SUCCESS;
    }
}
