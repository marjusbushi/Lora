# Lora PMS — release `staging` → `main` dhe rollback

Ky runbook përdoret për release-in multitenant të Villa Mucho. Production-i nuk
preket pa backup të freskët, CI green dhe aprovim eksplicit.

## Porta para deploy-it

1. Regjistro commit-in aktual të production-it si `PRE_DEPLOY_COMMIT`.
2. Sigurohu që production është clean dhe nuk ka migrime pending.
3. Ekzekuto backup-in off-server dhe verifiko `last-success`.
4. Restore backup-in në MySQL të izoluar dhe provo upgrade + rollback.
5. Kërko `application`, `mysql-migrations`, `mysql-tenancy` dhe `mysql-upgrade` green në PR.
6. Kryej smoke test në staging dhe merr aprovimin eksplicit.
7. Verifiko që user-i MySQL ka `TRIGGER`; me binary logging aktiv kërko edhe
   `log_bin_trust_function_creators=1` ose privilegjin administrativ përkatës.

## Deploy i kontrolluar

Deploy-i kryhet në maintenance mode. Para migrimit ruhen snapshot-i i integritetit,
batch-i aktual dhe commit-i i vjetër:

```bash
cd /var/www/villamucho
export PRE_DEPLOY_COMMIT="$(git rev-parse HEAD)"
export PREVIOUS_BATCH="$(php artisan tinker --execute='echo DB::table("migrations")->max("batch");')"
php artisan tenants:verify-integrity --snapshot=/var/lib/lora-backup/pre-release.json
php artisan down --retry=15
systemctl stop villamucho-queue.service
mv /etc/cron.d/villamucho-scheduler /var/lib/lora-backup/villamucho-scheduler.deploy-paused
php artisan schedule:interrupt
```

Pas vendosjes së kodit kandidat:

```bash
composer install --no-dev --optimize-autoloader
npm ci --no-audit --no-fund
npm run build
php artisan migrate --force
export RELEASE_BATCH="$(php artisan tinker --execute='echo DB::table("migrations")->max("batch");')"
php artisan tenants:verify-integrity \
  --compare=/var/lib/lora-backup/pre-release.json \
  --allow-additive-schema \
  --allow-additive-settings
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
systemctl start villamucho-queue.service
mv /var/lib/lora-backup/villamucho-scheduler.deploy-paused /etc/cron.d/villamucho-scheduler
```

Smoke testohen login-i, rezervimet, pagesat, Finance, POS, website dhe izolimi i
tenant-it. Vetëm pas tyre ekzekutohet `php artisan up`.

## Rollback i sigurt

Rollback-u i migrimeve lejohet vetëm sa aplikacioni është ende në maintenance
mode dhe para se versioni i ri të pranojë shkrime. Në atë dritare:

```bash
cd /var/www/villamucho
php artisan migrate:rollback --batch="$RELEASE_BATCH" --force
php artisan tenants:verify-integrity --compare=/var/lib/lora-backup/pre-release.json
git fetch origin --quiet
git reset --hard "$PRE_DEPLOY_COMMIT"
composer install --no-dev --optimize-autoloader
npm ci --no-audit --no-fund
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```

Nëse versioni i ri ka pranuar shkrime, migrimet nuk kthehen verbërisht. Mbaje
maintenance mode, bëj snapshot të ri dhe zgjidh mes një forward-fix-i ose restore-it
të backup-it. Restore mund të humbasë transaksionet pas snapshot-it, ndaj është
opsioni i fundit.

Migrimi i trigger/FK përdor DDL që auto-commit në MySQL. Nëse dështon në mes,
mos e ridrejto verbërisht: mbaji queue/scheduler të ndaluar, inventarizo kolonat,
FK-të dhe trigger-at e krijuar, dhe zgjidh ose cleanup të kontrolluar nga DBA ose
restore të backup-it të verifikuar. Vetëm pastaj përsërit migrimin.

## Rehearsal mbi kopjen production — 2026-07-15

- Production bazë: `a1efe66a57dd9d2aae9eca58111de3019cedb59d`.
- MySQL i provës: `8.0.46`, i njëjtë me production.
- Të 10 migrimet kandidate u aplikuan dhe u kthyen me batch të dedikuar.
- Integriteti multitenant, numrat dhe totalet financiare kaluan para/pas.
- Skema pas rollback-ut përputhet saktë për 64/64 tabela.
- Checksum-et e të dhënave përputhen saktë për 64/64 tabela.
- Kodi i vjetër hapi 206 routes, pa migrime pending, dhe lexoi DB-në e rikthyer.
- Suite finale: 587 teste, 3,896 assertions; build frontend green.

Kopja me PII dhe container-i i provës fshihen sapo dokumentimi dhe verifikimi të
përfundojnë.
