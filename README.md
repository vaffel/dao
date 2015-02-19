# PHP Data Access Object library
The DAO library makes interacting with database objects easy and abstracts away the hassle of keeping memcached and elasticsearch data up to date when making changes to the data.

# Installation
Add `"vafel\dao": "dev-master"` to the dependencies list of your project.

# Setup
Before the DaoFactory is used to retrieve DAO instances, the DAO must be set up;

```php
// Set up read only mysql
Vaffel\Dao\DaoFactory::setServiceLayer(
    Vaffel\Dao\DaoFactory::SERVICE_MYSQL,
    Vaffel\Dao\Models\Dao\MySQL::DB_TYPE_RO,
    $roPdo // PDO instance, with read-only access
);

// Set up read+write mysql
Vaffel\Dao\DaoFactory::setServiceLayer(
    Vaffel\Dao\DaoFactory::SERVICE_MYSQL,
    $rwPdo, // PDO instance, with write access
    Vaffel\Dao\Models\Dao\MySQL::DB_TYPE_RW
);
```

In addition to the RO and RW MySQL service, elasticsearch and memcached service layers needs to be set up;

```php
Vaffel\Dao\DaoFactory::setServiceLayer(
    Vaffel\Dao\DaoFactory::SERVICE_MEMCACHED,
    $memcached // Memcached instance
);

Vaffel\Dao\DaoFactory::setServiceLayer(
    Kidsa\DaoFactory::SERVICE_ELASTIC_SEARCH,
    $elasticClient // Elastica\Client instance
);
```

# Usage
After the initial setup, you can retrieve DAO instances for classes in your application by statically calling `getDao` on the DaoFactory class.

```php
$userDao = DaoFactory::getDao('MyApplication\Models\User');

// Return user with id 1337
$user = $userDao->fetch(1337);

// Set first name on the returned user
$user->setFirstName('Kristoffer');

// Persist the updated user object
$userDao->save($user);
```

The class name of the DAO class is found by naively adding `Dao\` after the last backslash in the model name.
