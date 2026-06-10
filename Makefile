#!make

DC = docker compose
EXEC = $(DC) exec app
ARTISAN = $(EXEC) php artisan

quality: rector pint stan
ci: quality testing

# -----------------------------------------------------------------------------
# Stack
# -----------------------------------------------------------------------------

restart: stop start

start:
	$(DC) up -d --remove-orphans

start-wait:
	$(DC) up -d --wait --remove-orphans

stop:
	$(DC) down

rebuild: destroy
	$(DC) build
	$(MAKE) start

destroy:
	$(DC) down -v --remove-orphans

shell:
	@$(EXEC) sh

# -----------------------------------------------------------------------------
# Code Quality
# -----------------------------------------------------------------------------

pint:
	@$(EXEC) ./vendor/bin/pint --parallel

stan:
	@$(EXEC) ./vendor/bin/phpstan analyse --memory-limit=2G

rector:
	@$(EXEC) ./vendor/bin/rector process

rector-dry:
	@$(EXEC) ./vendor/bin/rector process --dry-run

# -----------------------------------------------------------------------------
# Project helpers
# -----------------------------------------------------------------------------

testing:
	@$(EXEC) ./vendor/bin/phpunit

migrate:
	@$(ARTISAN) migrate

rollback:
	@$(ARTISAN) migrate:rollback

fresh:
	@$(ARTISAN) migrate:fresh

tinker:
	@$(ARTISAN) tinker

composer-install:
	@$(EXEC) composer install
