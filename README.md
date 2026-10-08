# JobQueueBundle

### Symfony bundle which aims to replace JMSJobQueueBundle for scheduling console commands, with complete browser interface.

## Table of Contents

1. [Features](#features)
2. [Installation](#installation)
3. [Configuration](#configuration)
    - [Bundles](#configbundlesphp)
    - [Routes](#configroutesyaml)
    - [Messenger](#configpackagesmessengeryaml)
    - [Security](#configpackagessecurityyaml)
    - [Job Queue](#configpackagesjob_queueyaml)
4. [Usage](#usage)
    - [Job Types](#types-of-ways-jobs-can-be-run)
    - [Creating Jobs Programmatically](#manually-creating-the-jobs-in-your-application)
    - [Creating Jobs via Browser](#creating-jobs-via-the-browser-interface)
5. [Testing](#testing)
6. [Dependencies](#dependencies)
7. [TODO](#todo)
8. [Additional info / Contributing](#additional-info--contributing)

## Features

- Schedule any command from your app as a server-side job, either programmatically or through a browser interface.
- Run jobs right away, postpone them or make them recurring.
- Browse jobs and see their details in browser.
- Cancel and retry jobs.
- Add related entity and parent job.
- Capture and store specific output from commands in the job's output parameters.

## Installation

```
composer require tomatom/jobqueuebundle
```

## Configuration

#### config/bundles.php:

```php
TomAtom\JobQueueBundle\JobQueueBundle::class => ['all' => true]
```

<hr>

#### config/routes.yaml:

```yaml
job_queue:
  resource: "@JobQueueBundle/src/Controller/"
  type: attribute
```

<hr>

#### config/packages/messenger.yaml:

You can create your own transport for the job messages - or just use *async* transport

```yaml
framework:
  messenger:
    # Your messenger config
    transports:
    # Your other transports
    job_message:
      dsn: "%env(MESSENGER_TRANSPORT_DSN)%"
      options:
        queue_name: job_message
    routing:
      TomAtom\JobQueueBundle\Message\JobMessage: job_message # or async
```

Recommendations for the transport of the job messages:

- **Disable retries** - `retry_strategy: { max_retries: 0 }`. A retry of a job message means running the command
  again. The handler itself never throws because a command failed (a failed command is stored as a `failed` job), the
  only exception it throws once the command has started is an `UnrecoverableMessageHandlingException` when the job
  result cannot be stored. A database error before the command starts (loading or claiming the job) is retried by the
  handler a few times and then thrown as a `RecoverableMessageHandlingException`, which Messenger retries even with
  `max_retries: 0` - nothing has run yet, so the job is not lost. A message of a job which does not exist (also after a
  short wait for the creating transaction) is rejected without retry.
- **Long jobs and several consumers** - the stock Doctrine transport redelivers a message which is not acknowledged
  within `redeliver_timeout` (default 3600 s). For jobs running longer than that, run the consumer with `--keepalive`
  (Symfony >= 7.2, `messenger:consume job_message --keepalive`) or raise `redeliver_timeout`. A redelivered message of
  a job which is already `running` does not run the command again (see `rerun_on_redelivery` below).
  **Without `--keepalive`, a job running longer than `redeliver_timeout` is redelivered to another consumer while the
  first one still runs it.** The handler cannot tell this from a dead worker: the job is marked `failed` with
  "previous worker died" (the first worker later overwrites the status with its result, the message stays in the
  output), and with `rerun_on_redelivery: true` the command runs twice at the same time.
- **Own DBAL connection (optional)** - the handler keeps no transaction open while the command runs, so sharing the
  default connection is fine. If you want to be completely sure the handler's connection state never meets the
  transport's send/reject, use a separate connection to the same database, e.g. `dsn: "doctrine://jobs"` with a
  `doctrine.dbal.connections.jobs` entry.
- Do not wrap the job handler in the `doctrine_transaction` middleware - the job state must be visible (and the
  cancellation readable) while the command runs. (A transaction opened by the caller is not rolled back by the
  handler's connection resets, but with a lost connection it is gone anyway.)

```yaml
framework:
  messenger:
    transports:
      job_message:
        dsn: "%env(MESSENGER_TRANSPORT_DSN)%"
        retry_strategy:
          max_retries: 0
        options:
          queue_name: job_message
          # redeliver_timeout: 3600 # raise for jobs longer than an hour if you do not use --keepalive
```

<hr>

#### config/packages/security.yaml:

The bundle uses the role security system to control access for the jobs/command scheduling. You can assign roles based
on the
level of access you want to grant to each user.

Available roles are:

**ROLE_JQB_ALL** - The main role with full permissions. Provides unrestricted access to all features of
the bundle.

**ROLE_JQB_JOBS** - Grants full permissions for jobs (JOB_ roles).

**ROLE_JQB_COMMANDS** - Grants full permissions for command scheduling (COMMAND_ roles).

**ROLE_JQB_JOB_LIST** - Allows access to view the job list.

**ROLE_JQB_JOB_READ** - Allows access to view job details.

**ROLE_JQB_JOB_CREATE** - Allows creating new jobs.

**ROLE_JQB_JOB_DELETE** - Allows deleting jobs.

**ROLE_JQB_JOB_CANCEL** - Allows canceling jobs.

**ROLE_JQB_COMMAND_SCHEDULE** - Allows scheduling commands.

(Also with constants in [JobQueuePermissions.php](src/Security/JobQueuePermissions.php))

To grant full access to users, add **ROLE_JQB_ALL** to the role hierarchy:

```yaml
security:
  role_hierarchy:
    ROLE_ADMIN:
      - ROLE_JQB_ALL
```

To restrict access for example to only viewing the job list and job details (without creation or scheduling), configure
the roles like this:

```yaml
security:
  role_hierarchy:
    ROLE_USER:
      - ROLE_JQB_JOB_LIST
      - ROLE_JQB_JOB_READ
```

#### Note - jobs creation is always possible where security has no loaded user, for example if created in a command.

<hr>

#### config/packages/job_queue.yaml:

You do not have to create this file for the bundle to work, but you can edit some parameters

```yaml
job_queue:
  database:
    job_table_name: "your_job_table_name" # Default = job_queue
    job_recurring_table_name: "your_job_recurring_table_name" # Default = job_recurring_queue
  scheduling:
    heartbeat_interval: "1 hour" # Default = 1 minute
  processing:
    poll_interval_ms: 1000 # Default = 1000 - how often the output is written and the cancellation checked
    output_max_bytes: 4194304 # Default = 4 MB - cap of the stored output, 0 = unlimited (not recommended)
    db_failure_tolerance: 30 # Default = 30 - consecutive failed database polls (~ seconds) before the job is given up
    rerun_on_redelivery: false # Default = false - run the command again when a message of a RUNNING job is redelivered
```

How a job is processed:

- The job is claimed atomically (`planned` -> `running`), so a duplicate message never runs the command twice.
- While the command runs, its output is appended to the job and the cancellation is checked at most once per
  `poll_interval_ms`. All these writes run in autocommit mode through DBAL - no transaction is ever held open by the
  handler, and the `Job` entity is not managed by the entity manager during the run.
- A short database outage does not stop the command: the output stays buffered in memory and the write is retried.
  After `db_failure_tolerance` consecutive failed polls the command is stopped and the job is marked `failed`.
- Output above `output_max_bytes` is dropped and a `[... output truncated by JobQueueBundle at N bytes ...]` marker is
  appended once. **Keep `output_max_bytes` well below MySQL `max_allowed_packet`** (64 MB by default on MySQL 8) -
  the `output` column is a LONGTEXT and a larger value cannot be written or read in one packet. With
  `output_max_bytes: 0` nothing guards the column size, not even the final status message.
- The stored output is always valid UTF-8 (a strict MySQL rejects anything else in a `utf8mb4` column): a character
  split between two reads is completed first, the cap never cuts a character, and bytes which are not UTF-8 (binary
  output, another encoding) are replaced by `?`.
- Output which the database rejects although the connection works (e.g. a value or packet size error) is replaced by
  a `[JobQueueBundle: N bytes of output could not be stored: ...]` note - it never fails a healthy command or blocks
  storing the job result.
- A job deleted while its command runs stops the command (like a cancellation); nothing is recorded.
- When a message is redelivered for a job which is already `running` (the previous worker died), the job is marked
  `failed` with an explanation instead of running the command again. Set `rerun_on_redelivery: true` to run it again.
  Messages of `completed`, `failed` or `cancelled` jobs are ignored, a message of a deleted job is rejected without
  retry.

**Upgrading from 2.1 with a job stuck in `running`** (redelivered over and over because its output grew close to
`max_allowed_packet`): truncate its output before deploying, e.g.
`UPDATE job_queue SET output = LEFT(output, 1048576) WHERE id = <id>`. Its next redelivery marks it `failed`; the
status message is only appended while the output is below the cap, and later output chunks of such a job are dropped.

#### Update your database so the job tables are created

```shell
php bin/console d:s:u --force
```

or via migrations.

#### Do not forget to run the messenger

This is up to you and where your project runs, but you need to have the messenger consuming the right transport for the
bundle to work.

```shell
php bin/console messenger:consume job_message
```

For recurring messages you also need the scheduler running so the jobs are created

```shell
php bin/console messenger:consume scheduler_job_recurring
```

## Usage

#### Types of ways jobs can be run

- **Once** - Runs once right after creation (Job entity)
- **Once postponed** - Runs once on given time (Job entity)
- **Recurring**
    - Runs repeatedly on time by the
      given [Symfony scheduler cron expression](https://symfony.com/doc/current/scheduler.html#cron-expression-triggers)
      (JobRecurring entity which
      creates new Job entity on every run)
    - Changes in the recurring jobs (adding/deleting/editing) are handled by the "heartbeat" message, which runs on the
      given interval (default is 1 minute but can be edited in the config file - if you do not add / edit them often,
      you can set it to higher value)

Once job is created, [Symfony messenger](https://symfony.com/doc/current/messenger.html) message is created which
handles the run of the command from the job.

### Manually creating the jobs in your application:

The function __createCommandJob__ from __CommandJobFactory__ accepts:

* command name
* command parameters
* ID of related entity (optional)
* name of related entity class - (optional)
* job entity for parent job (optional)
* entity of recurring parent job (optional)
* datetime of postponed job start (optional)
* check user role (optional)

and returns the created job.

**Basic example**:

```php
$commandName = 'app:your:command';

$params = [
    '--param1=' . $request->get('param1'),
    '--param2=' . $request->get('param2'),
];

// Try to create the command job
try {
    $job = $this->commandJobFactory->createCommandJob($commandName, $params);
} catch (OptimisticLockException|ORMException|CommandJobException $e) {
    // Redirect back upon failure
    $this->logger->error('createCommandJob error: ' . $e->getMessage());
    return $this->redirectToRoute('your_route');
}

// Redirect to the command job detail
return $this->redirectToRoute('job_queue_detail', ['id' => $job->getId()]);
```

**Adding a related entity**:

Purpose of this is to filter jobs seen in the list by the related entity.

For example, if you have a Customer entity:

```php
$job = $this->commandJobFactory->createCommandJob($commandName, $params, $customer->getId(), Customer::class);
```

If you then go to the job list with parameters /job/list/**Customer**/**1** (which is being automatically
added if going from the detail with related entity) or if you add it to the list path yourself like:

```twig
<a href="{{ path('job_queue_list', {'name': constant('class', customer), 'id': customer.id}) }}">{{ 'job.job_list'|trans }}</a>
```

then the job list only contains jobs for that given customer.

You can also only add the entity name to get all jobs for a given entity.

**Adding a parent job**:

Jobs can have another one as a parent job. One job can have multiple children jobs.

This can be used if for example you need to create a job that has to run after another job finishes.

(Recreating jobs also creates a new one with the original as a parent.)

```php
// Retrieve another job entity to add as a parent job
$parentJob = $this->entityManager->getRepository(Job::class)->findOneBy(['command' => $command, 'status' => Job::STATUS_COMPLETED]);
$job = $this->commandJobFactory->createCommandJob($commandName, $params, null, null, $parentJob);
```

If jobs have any children/parent there will be button links to them in the job detail (for parents also in job list).

**Creating a postponed job**:

If you want to set a command to run once in given time - set $startAt of type DateTimeImmutable

```php
$startAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $postponedDateTime);
$job = $commandJobFactory->createCommandJob($commandName, $params, $listId, $listName, null, null, $startAt);
```

**Creating / updating a recurring job**:

```php
$jobRecurring = $this->entityManager->getRepository(JobRecurring::class)->find($id);
if ($jobRecurring) {
    $commandJobFactory->updateRecurringCommandJob($jobRecurring, $commandName, $params, $frequency, $active);
} else {
    $commandJobFactory->createRecurringCommandJob($commandName, $params, $frequency, $active);
}
```

Where both functions call the function __saveRecurringCommandJob__ from __CommandJobFactory__, which accepts:

* job recurring - updated recurring job (only on updateRecurringCommandJob)
* command name
* command params
* frequency
  of type [Symfony scheduler cron expression](https://symfony.com/doc/current/scheduler.html#cron-expression-triggers)
* is active

**Saving values from the command output**:

If you need to retrieve and save any data from the output of a command that is running from a job, you can do that by
adding anything after
constant **Job::COMMAND_OUTPUT_PARAMS** in the command output, for example:

```php
$io->info(Job::COMMAND_OUTPUT_PARAMS . $customerId) // $customerId = 123;
```

This will output in the console **OUTPUT PARAMS: 123** and the '123' will be saved in the job's **outputParams**, which
can be then used for example to retrieve the customer entity.

```php
$customer = $this->entityManager->getRepository(Customer::class)->find($job->getOutputParams());
```

Output params are saved in the database as a TEXT and you can save multiple values, which are then separated by a comma,
for example:

```php
$io->info(Job::COMMAND_OUTPUT_PARAMS . 123);
$io->info(Job::COMMAND_OUTPUT_PARAMS . 'some text value');
$io->info(Job::COMMAND_OUTPUT_PARAMS . implode(['a', 'b']));
```

this will be saved as '123, some text value, ab' and then you need to individually handle getting the values by
what you've
saved.

### Creating jobs via the browser interface:

Available urls:

- **command/schedule** - Create a command to run as job
- **command/schedule/{id}** - Edit recurring job
- **job/list/{name}/{id}** - List jobs (related entity name+id)
- **job/recurring/list** - List recurring jobs
- **job/{id}** - Job detail with command output

| Schedule Command                                                 | Job List                                         | Job Detail                                           |
|------------------------------------------------------------------|--------------------------------------------------|------------------------------------------------------|
| <img src="docs/img_schedule_command.png" alt="Schedule Command"> | <img src="docs/img_job_list.png" alt="Job List"> | <img src="docs/img_job_detail.png" alt="Job Detail"> |

Job detail gets updated automatically while the job is running.

**All the pages are also responsive for mobile use.**

Extending the templates can be done like this:

```twig
{# templates/job/detail.html.twig #}

{% extends '@JobQueue/job/detail.html.twig' %}

{% block title %}...{% endblock %}

{% block header %}...{% endblock %}

{% block body %}...{% endblock %}
```

To change or add translations for a new locale, use translation variables from bundle's translations in your
translations/messages.{locale}.yaml:

(Currently there are only translations for *en* and *cs* locales)

## Testing

The bundle has tests for job creation and integration tests of the job processing in the tests/ folder.
The integration tests run the real message handler with real subprocesses against a temporary SQLite database, so
they need the `pdo_sqlite` PHP extension.
Running tests in your app can be done like this:

```bash
vendor/bin/phpunit vendor/tomatom/jobqueuebundle/tests/
```

The tests are also run on every push / pull request on GitHub.

## Dependencies

* "php": ">=8.1",
* "doctrine/doctrine-bundle": "^2|^3",
* "doctrine/orm": "^2|^3",
* "dragonmantank/cron-expression": "^3",
* "knplabs/knp-paginator-bundle": "^6",
* "psr/log": "^1|^2|^3",
* "spiriitlabs/form-filter-bundle": "^10|^11|^12",
* "symfony/form": "^6.4 || ^7.4",
* "symfony/framework-bundle": "^6.4 || ^7.4",
* "symfony/lock": "^6.4 || ^7.4",
* "symfony/messenger": "^6.4 || ^7.4",
* "symfony/process": "^6.4 || ^7.4",
* "symfony/scheduler": "^6.4 || ^7.4",
* "symfony/security-bundle": "^6.4 || ^7.4",
* "symfony/translation": "^6.4 || ^7.4",
* "twig/twig": "^2|^3"

## TODO

- [Handle getting changes of recurring jobs in better way](/../../issues/3)
- Handle jobs output better (key:value)

## Additional info / Contributing

Special thanks to [schmittjoh](https://github.com/schmittjoh) for the
original [JMSJobQueueBundle](https://github.com/schmittjoh/JMSJobQueueBundle).

This bundle **is not a fork, nor is building on top of the original bundle**, it's our own take on the console
command scheduling, so please bear that in mind when using it. However, going from the original to this bundle should be
seamless.

Feel free to open any issues or pull requests if you find something wrong or missing what you'd like the bundle to have!
