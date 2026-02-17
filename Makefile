.DEFAULT_GOAL := help
.PHONY: help install test test-coverage stan cs cs-fix lint shell

DOCKER_RUN := docker compose run --rm php sh -c

help: ## Show this help message
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z_-]+:.*##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

install: ## Build the Docker image and install Composer dependencies
	docker compose build --quiet
	$(DOCKER_RUN) "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet 2>/dev/null && composer install --no-interaction --prefer-dist"

test: ## Run the PHPUnit test suite
	$(DOCKER_RUN) "vendor/bin/phpunit --testdox"

test-coverage: ## Run tests and generate HTML coverage report (uses Xdebug from the image)
	$(DOCKER_RUN) "XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html coverage/ --testdox"

stan: ## Run PHPStan static analysis (level 8)
	$(DOCKER_RUN) "vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=512M"

cs: ## Check code style with PHP-CS-Fixer (dry-run, no changes)
	$(DOCKER_RUN) "vendor/bin/php-cs-fixer fix --dry-run --diff"

cs-fix: ## Apply code-style fixes with PHP-CS-Fixer
	$(DOCKER_RUN) "vendor/bin/php-cs-fixer fix"

lint: ## PHP syntax check on all source files
	$(DOCKER_RUN) "find src tests -name '*.php' | xargs -P4 -n1 php -l | grep -v 'No syntax errors' | grep . && exit 1 || echo 'All files OK.'"

shell: ## Open a shell inside the PHP Docker container
	docker compose run --rm php sh
