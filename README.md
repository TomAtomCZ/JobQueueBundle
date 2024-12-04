# JobQueueBundle

### Symfony bundle which aims to replace JMSJobQueueBundle for scheduling console commands, leveraging Symfony Messenger for job handling.

## Features:

- Schedule any command from your app as a server-side job, either programmatically or through a browser interface.
- Browse jobs and see their details in browser.
- Cancel and retry jobs.
- Add related entity and parent job.
- Capture and store specific output from commands in the job's output parameters.

#### Dependencies:

* php: >=8.1
* doctrine/doctrine-bundle: ^2
* doctrine/orm: ^2|^3
* symfony/framework-bundle: ^6.4
* symfony/messenger: ^6.4
* symfony/process: ^6.4
* symfony/translation: ^6.4
* twig/twig: ^2|^3

## Installation:

```
composer require tomatom/jobqueuebundle
```

## Configuration:

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

You can create own transport for the job messages - or just use *async* transport

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
      'TomAtom\JobQueueBundle\Message\JobMessage': job_message # or async
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

#### Update your database so the __'job_queue'__ table is created

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

## Usage:

### Manually creating the jobs in your application:

The function __createCommandJob__ from __CommandJobFactory__ accepts:

* command name,
* command parameters,
* ID of related entity (optional)
* name of related entity class - self::class (optional)
* job entity for parent job (optional)

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

If you then go to the job list with parameters /job/list/**1**/**App\Entity\Customer** (which is being automatically
added if going from the detail with related entity) or if you add it to the list path yourself like:

```twig
<a href="{{ path('job_queue_list', {'id': customer.id, 'name': constant('class', customer)}) }}">{{ 'job.job_list'|trans }}</a>
```

then the job list only contains jobs for that given customer.

You can also only add the entity ID.

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

On the url __/command__ you can schedule all commands from your application (Symfony ones included):

![img_schedule_command.png](docs/img_schedule_command.png)

On the url __/job/list__ you can see all your jobs

![img_job_list.png](docs/img_job_list.png)

On the url __/job/{id}__ you can see the detail of each job

![img_job_detail.png](docs/img_job_detail.png)

Note - the design will probably change for the better, but you can create your own.

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

## TODO:

Add tests<br>
Add configuration for things such as table name

## Contributing:

Feel free to open any issues or pull requests if you find something wrong or missing what you'd like the bundle to have!