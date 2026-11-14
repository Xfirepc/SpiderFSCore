#!/usr/bin/env bash
set -euo pipefail

test_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
app_root="$(cd -- "$test_dir/../../.." && pwd)"
vet_db_container="spidervet-tests-$$"
trap 'docker rm -fv "$vet_db_container" >/dev/null 2>&1 || true' EXIT

docker run -d --name "$vet_db_container" --network none \
    -e MYSQL_ROOT_PASSWORD=spidervet-test-only -e MYSQL_DATABASE=spidervet_test \
    "${SPIDERVET_TEST_DB_IMAGE:-mysql:8.0}" >/dev/null

vet_db_ready=false
for attempt in {1..50}; do
    if docker exec -e MYSQL_PWD=spidervet-test-only "$vet_db_container" sh -c \
        'if command -v mariadb-admin >/dev/null; then exec mariadb-admin -h127.0.0.1 -uroot ping --silent; else exec mysqladmin -h127.0.0.1 -uroot ping --silent; fi' >/dev/null 2>&1; then
        vet_db_ready=true
        break
    fi
    sleep 1
done
if [[ "$vet_db_ready" != true ]]; then
    echo 'No se pudo iniciar la base de pruebas de SpiderVet.' >&2
    exit 1
fi

vet_output_args=()
if [[ -n "${SPIDERVET_TEST_OUTPUT_DIR:-}" ]]; then
    mkdir -p -- "$SPIDERVET_TEST_OUTPUT_DIR"
    vet_output_args=(-e SPIDERVET_TEST_OUTPUT=1 -v "$SPIDERVET_TEST_OUTPUT_DIR:/test-output")
fi
docker run --rm --network "container:$vet_db_container" --entrypoint sh \
    -e SPIDERVET_TEST=1 -v "$app_root:/app:ro" "${vet_output_args[@]}" \
    "${SPIDERVET_TEST_PHP_IMAGE:-main_spidercodeapp-php:latest}" \
    -c 'php /app/Plugins/SpiderVet/Test/integration.php && php /app/Plugins/SpiderVet/Test/deployment.php'
