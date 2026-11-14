#!/usr/bin/env bash
set -euo pipefail
source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
test_folder="$(mktemp -d -t recovery-tests-XXXXXXXX)"
container_name="recovery-$(basename "$test_folder")"
php_image="spidercode/php-migration-audit:8.4.27"
cleanup() {
    docker rm -f "$container_name-mail" "$container_name" >/dev/null 2>&1 || true
    docker run --rm --network none -v "$test_folder:/work" --entrypoint sh "$php_image" -c 'rm -rf /work/runtime /work/token' >/dev/null 2>&1 || true
    rm -rf "$test_folder"
}
trap cleanup EXIT
mkdir -p "$test_folder/runtime/MyFiles/Tmp/FileCache" "$test_folder/runtime/Dinamic/Table"
for folder in Core Plugins; do ln -s "/source/$folder" "$test_folder/runtime/$folder"; done
for folder in Model Lib; do ln -s "/source/Dinamic/$folder" "$test_folder/runtime/Dinamic/$folder"; done
cp -a "$source_root/Dinamic/View" "$test_folder/runtime/Dinamic/View"
cp "$source_root"/Core/View/Login/Recovery*.twig "$test_folder/runtime/Dinamic/View/Login/"
cp "$source_root"/Dinamic/Table/*.xml "$test_folder/runtime/Dinamic/Table/"
docker run -d --name "$container_name" --network none --tmpfs /var/lib/mysql:rw,size=1g -e MARIADB_ROOT_PASSWORD=recovery-test-only mariadb:10.7 >/dev/null
docker run -d --name "$container_name-mail" --network "container:$container_name" axllent/mailpit:v1.27 >/dev/null
for attempt in {1..60}; do
    if docker exec -e MYSQL_PWD=recovery-test-only "$container_name" mysqladmin --protocol=tcp -h127.0.0.1 ping -uroot --silent >/dev/null 2>&1; then break; fi
    sleep 1
done
run_php() {
    docker run --rm --network "container:$container_name" -e RECOVERY_ISOLATED=1 -v "$source_root:/source:ro" -v "$test_folder:/work" --entrypoint php "$php_image" -d error_reporting=22527 /source/Test/Security/recovery-integration.php "$@"
}
run_php setup
run_php suite
run_php tenant
if [[ -n "${RECOVERY_VISUAL_OUTPUT:-}" ]]; then
    mkdir -p "$RECOVERY_VISUAL_OUTPUT"
    cp "$test_folder"/*.html "$RECOVERY_VISUAL_OUTPUT/"
fi
run_php race > "$test_folder/race1" & first=$!
run_php race > "$test_folder/race2" & second=$!
wait "$first" "$second"
python3 - "$test_folder" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
assert sorted([(p/'race1').read_text(),(p/'race2').read_text()])==['rejected','winner']
print('Concurrencia: exactamente un cambio confirmado')
PY
