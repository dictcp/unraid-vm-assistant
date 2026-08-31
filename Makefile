.PHONY: test build validate

test:
	mise exec -- php tests/run.php

build:
	mise exec -- php scripts/build-plugin.php

validate: test build
	bash -n src/scripts/vm.sh
	mise exec -- php -l src/VMCreationAssistant.page
	mise exec -- php -l src/VMManagerIntegration.page
	mise exec -- php -l src/lib/VMProvisioner.php
	mise exec -- php -l src/scripts/create-vm.php
	mise exec -- php -l scripts/build-plugin.php
	mise exec -- php -l scripts/validate-plugin.php
	mise exec -- php scripts/validate-plugin.php
