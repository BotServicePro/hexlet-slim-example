start:
	php -S localhost:8080 -t public public/index.php

install:
	composer install

lint:
	composer run-script phpcs -- --standard=PSR12 public

lint-fix:
	composer run-script phpcbf -- --standard=PSR12 public