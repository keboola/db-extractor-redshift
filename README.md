# Redshift DB Extractor

## Example configuration
```json
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
      ],
      "propagateDescriptions": true
    }
```

## Table and column descriptions

By default the extractor reads the Redshift `COMMENT ON TABLE` and
`COMMENT ON COLUMN` values of the extracted table and writes them to the
description of the corresponding table and columns in Storage. This happens on
every run, so a comment changed in Redshift reaches Storage on the next
extraction.

Set `propagateDescriptions` to `false` to turn this off. No descriptions are
then written and no comment is read at all.

Known limitations:

- Descriptions are only available when a table is configured via `table`. In
  advanced query mode (`query`) the column list comes from the query result
  itself, which carries no comments, so nothing is propagated.
- A comment consisting of whitespace only is treated as no description.
- Removing a comment in Redshift does not clear the description already stored
  in Storage, it only stops being refreshed.
- Columns of a late binding view get no description. Redshift does not expose
  them in `pg_attribute`, which is where the comments are attached. The comment
  of the view itself is propagated.

## Running Tests

1. Create Redshift cluster 
2. Create S3 bucket from CloudFormation template `aws-services.json`
3. Create `.env` file and fill in you Redshift and S3 credentials:
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
4. Install composer dependencies locally
```sh
docker compose run --rm dev composer install
```
5. Run the tests:

```sh
docker compose run --rm app
```

Run single test example:
```sh
docker compose run --rm dev ./vendor/bin/phpunit --debug --filter testGetTables
```

## License

MIT licensed, see [LICENSE](./LICENSE) file.
