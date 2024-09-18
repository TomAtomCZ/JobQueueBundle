# JobQueueBundle

### Symfony Bundle which aims to replace JMSJobQueueBundle console commands scheduling, using Symfony messenger.

#### Dependencies:

* php: >=8.1
* doctrine/doctrine-bundle: ^2
* doctrine/orm: ^2|^3
* symfony/framework-bundle: ^6.4
* symfony/messenger: ^6.4
* symfony/process: ^6.4
* symfony/translation: ^6.4
* twig/twig: ^3

### Installation:

```
composer require tomatom/jobqueuebundle
```

### Configuration:

#### config/bundles.php:

```php
TomAtom\JobQueueBundle\JobQueueBundle::class => ['all' => true]
```

#### config/routes.yaml:

```yaml
job_queue:
  resource:
    path: '../vendor/tomatom/jobqueuebundle/src/Controller'
    namespace: TomAtom\JobQueueBundle\Controller
  type: attribute
```

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

#### Update your database so the __'job_queue'__ table is created

```shell
php bin/console d:s:u --complete --force
```

or via migrations if you are using them.

### Usage:

So far there is only one function __createCommandJob__, which accepts:

* command name,
* command parameters,
* ID of related entity (optional)
* Name of related entity class - self::class (optional)

and returns ID of the created job, for example:

```php
$commandName = 'app:your:command';

$params = [
    '--param1=' . $request->get('param1'),
    '--param2=' . $request->get('param2'),
];

// Try to create the command job
try {
    $job = $this->commandJobFactory->createCommandJob($commandName, $params, $entity->getId(), Entity::class);
} catch (OptimisticLockException|ORMException|CommandJobException $e) {
    // Redirect back upon failure
    $this->logger->error('createCommandJob error: ' . $e->getMessage());
    return $this->redirectToRoute('your_route');
}

// Redirect to the command job detail
return $this->redirectToRoute('job_queue_detail', ['id' => $job->getId()]);
```

Extending the templates can be done like this:

```twig
{# templates/job/detail.html.twig #}

{% extends '@JobQueue/job/detail.html.twig' %}

{% block title %}...{% endblock %}

{% block header %}...{% endblock %}

{% block body %}...{% endblock %}
```

To change or add translations for a new locale, use those translation variables in your
translations/messages.{locale}.yaml:

(Currently there are only translations for *en* and *cs* locales)

```yaml
job.detail: 'Detail'
job.detail.title: "Planned task"
job.detail.refresh: "Refresh"
job.detail.back.to.list: "Back to list"
job.detail.runtime: "Runtime"
job.detail.closed: "Closed"
job.detail.output: "Output"
job.list.title: "Planned tasks"
job.list.related.entity: "Entity ID"
job.list.back.to.list: "Back to tasks"
job.header.for.entity: "for entity with ID"
job.command: "Command"
job.state: "State"
job.created: "Created"
job.runtime.hours: "hours"
job.runtime.minutes: "minutes"
job.runtime.seconds: "seconds"
job.already.exists: "The same job is already planned."
```