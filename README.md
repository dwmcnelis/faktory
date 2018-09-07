# Faktory (PHP)

## Add jobs

```php
$client = new FaktoryClient($faktoryHost, $faktoryPort);
$job = new FaktoryJob($id, $type, $args);
$client->push($job);
```

## Process jobs

```php
$client = new FaktoryClient($faktoryHost, $faktoryPort);
$worker = new FaktoryWorker($client);
$worker->register('somejob', function ($job) {
    echo "You got the job buddy!\n";
    var_dump($job);
});

$worker->setQueues(['critical', 'default', 'bulk']);
$worker->run();
```port
