# JobQueueBundle

### Symfony Bundle which aims to replace JMSJobQueueBundle console commands scheduling, using Symfony messenger.

#### Dependencies:

* doctrine/doctrine-bundle: ^2.10
* doctrine/orm: ^2.15
* symfony/framework-bundle: ^6.3
* symfony/messenger: ^6.3
* symfony/process: ^6.3
* symfony/translation: ^6.3
* twig/twig: ^3.6

### Installation:

```
composer require tomatomcz/jobqueuebundle
```

### Configuration:

config/bundles.php:

```php
TomAtom\JobQueueBundle\JobQueueBundle::class => ['all' => true]
```

config/services.yaml:

```yml
TomAtom\JobQueueBundle\:
  resource: '../vendor/tomatomcz/jobqueuebundle/src/*'
  exclude: '../vendor/tomatomcz/jobqueuebundle/src/{DependencyInjection,Entity,Tests,Kernel.php}'
```

config/routes.yaml:

```yaml
jobqueue:
  resource:
    path: '../vendor/tomatomcz/jobqueuebundle/src/Controller'
    namespace: TomAtom\JobQueueBundle\Controller
  type: attribute
```

config/packages/messenger.yaml:

```yaml
framework:
  messenger:
    ...
    routing:
      'TomAtom\JobQueueBundle\Message\JobMessage': async # or 'sync'
```

config/packages/twig.yaml:

```yaml
twig:
  ...
  paths:
    '%kernel.project_dir%/vendor/tomatomcz/jobqueuebundle/templates': TomAtomJobQueue
```

#### And update your database so the __'job'__ table is created

```shell
bin/console d:s:u --complete --force
```

### Usage:

So far there is only one function __createCommandJob__, which accepts:

* command name,
* command parameters,
* ID of related entity (optional)

and returns ID of the created job, for example:

```php
$commandName = 'app:your:command';

$params = [
    '--param1=' . $request->get('param1'),
    '--param2=' . $request->get('param2'),
];

// Try to create the command job
try {
    $job = $this->commandJobFactory->createCommandJob($commandName, $params, $entity->getId());
} catch (OptimisticLockException|ORMException $e) {
    // Redirect back upon failure
    $this->logger->error('createCommandJob error: ' . $e->getMessage());
    return $this->redirectToRoute('your_route');
}

// Redirect to the command job detail
return $this->redirectToRoute('job_queue_detail', ['id' => $job->getId()]);
```

Create templates/job/ __detail__ and __list__ twig templates, which you will propably want to edit like this:

```twig
{# templates/job/detail.html.twig #}

{% extends "@TomAtomJobQueue/job/detail.html.twig" %}

{% block title %}...{% endblock %}

{% block header %}...{% endblock %}

{% block body %}...{% endblock %}
```