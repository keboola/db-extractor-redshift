[![Build Status](https://travis-ci.com/keboola/db-extractor-redshift.svg?branch=master)](https://travis-ci.com/keboola/db-extractor-redshift)

# Redshift DB Extractor

## Example configuration

    {
      "db": {
        "driver": "redshift",
        "host": "HOST",
        "port": "PORT",
        "database": "DATABASE",
        "user": "USERNAME",
        "password": "PASSWORD",
        "ssh": {
          "enabled": true,
          "keys": {
            "private": "ENCRYPTED_PRIVATE_SSH_KEY",
            "public": "PUBLIC_SSH_KEY"
          },
          "sshHost": "PROXY_HOSTNAME"
        }
      },
      "tables": [
        {
          "id": 1,
          "name": "employees",
          "query": "SELECT * FROM employees",
          "outputTable": "in.c-main.employees",
          "incremental": false,
          "enabled": true,
          "primaryKey": null
        }
      ]
    }

## Running Tests

1. Create Redshift cluster and S3 bucket from CloudFormation template `aws-services.json`
2. Create `.env` file and fill in you Redshift and S3 credentials:
```
REDSHIFT_DB_HOST=my.redshift.host.region.amazonaws.com
REDSHIFT_DB_PORT=5439
REDSHIFT_DB_DATABASE=testdb
REDSHIFT_DB_USER=testuser
REDSHIFT_DB_PASSWORD=testpassword
REDSHIFT_DB_SCHEMA=testschema
AWS_ACCESS_KEY=aws_access_key
AWS_SECRET_KEY=aws_secret_key
AWS_REGION=eu-west-1
AWS_S3_BUCKET=test-bucket
```
3. Install composer dependencies locally and load test fixtures to S3
```$xslt
docker-compose run --rm dev php ./tests/loadS3.php
docker-compose run --rm dev composer install
```
4. Run the tests:

```
docker-compose run --rm app
```

Run single test example:
```
docker-compose run --rm dev ./vendor/bin/phpunit --debug --filter testGetTables
```

## License

MIT licensed, see [LICENSE](./LICENSE) file.
