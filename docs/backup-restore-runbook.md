# Lora PMS — backup off-server dhe restore drill

## Arkitektura e aprovuar

- Production mbetet në Hetzner.
- Kopja e enkriptuar ruhet në bucket-in privat Backblaze B2 EU
  `lora-pms-prod-eu-backup-7c14a9`.
- Endpoint-i S3 është `s3.eu-central-003.backblazeb2.com`.
- Restic enkripton çdo backup në client para upload-it; bucket-i ka edhe SSE-B2.
- Object Lock është aktiv në bucket-in burim, por retention default mbahet i çaktivizuar.
  Backblaze paralajmëron se retention-i default në një repository aktiv backup-i mund
  të shkaktojë humbje të paparashikueshme të të dhënave gjatë mirëmbajtjes së tij.
- Kopja immutable vendoset në një bucket të dytë B2 me retention `compliance` dhe
  Cloud Replication nga bucket-i burim. Kështu Restic punon normalisht në burim,
  ndërsa replica nuk mund të fshihet para skadimit të retention-it.
- Bucket-i destinacion `lora-pms-prod-eu-immutable-7c14a9` u krijua privat më
  2026-07-14 me SSE-B2, Object Lock dhe retention default 30 ditë `compliance`.
- Cloud Replication `lora-prod-immutable-30d` kopjon automatikisht nga
  `lora-pms-prod-eu-backup-7c14a9` te bucket-i immutable, duke përfshirë edhe
  skedarët ekzistues. Rregulli u aktivizua më 2026-07-14.
- Snapshot-i test `a2473916` u krijua pas aktivizimit dhe skedarët e tij të rinj
  u shfaqën në bucket-in immutable, duke verifikuar rrjedhën fund-më-fund.
- Backblaze replikon skedarët ekzistues në ciklin ditor pas mesnatës UTC. Numri
  i objekteve burim/destinacion kontrollohet pas këtij cikli para se 9.2 të
  shënohet i përfunduar.

## Secrets në server

Secrets nuk futen në git, chat ose `.env` të aplikacionit. Ato ruhen vetëm në:

- `/etc/lora-backup/restic.env` — `root:root`, mode `0600`;
- `/etc/lora-backup/restic-password` — `root:root`, mode `0600`.

Shembulli i strukturës (pa vlera reale):

```bash
AWS_ACCESS_KEY_ID=<restricted-bucket-key-id>
AWS_SECRET_ACCESS_KEY=<restricted-bucket-application-key>
RESTIC_REPOSITORY=s3:s3.eu-central-003.backblazeb2.com/lora-pms-prod-eu-backup-7c14a9
RESTIC_PASSWORD_FILE=/etc/lora-backup/restic-password
RESTIC_CACHE_DIR=/var/cache/restic
```

Application Key duhet të ketë akses vetëm te ky bucket. Master Key nuk përdoret.
Fjalëkalimi Restic ruhet edhe në password manager; humbja e tij e bën backup-in të
parikuperueshëm.

Më 2026-07-14, fjalëkalimi i rikuperimit u ruajt si hyrje personale në Zoho Vault
me emrin `Lora PMS Production — Restic Recovery`. Vlera sekrete nuk ruhet në git
ose në këtë dokument.

## Instalimi në production

Instalimi production u krye më 2026-07-14. Komandat e riprodhueshme janë:

```bash
sudo apt-get update
sudo apt-get install -y restic
sudo install -d -m 0700 -o root -g root /etc/lora-backup /var/lib/lora-backup /var/cache/restic
sudo install -m 0700 -o root -g root /var/www/villamucho/ops/backup/run-offsite-backup.sh /usr/local/sbin/lora-offsite-backup
sudo cp /var/www/villamucho/ops/backup/lora-backup.service /etc/systemd/system/
sudo cp /var/www/villamucho/ops/backup/lora-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
```

Pas vendosjes së secrets:

```bash
sudo bash -c 'set -a; source /etc/lora-backup/restic.env; set +a; restic init'
sudo systemctl start lora-backup.service
sudo systemctl status lora-backup.service
sudo systemctl enable --now lora-backup.timer
sudo systemctl list-timers lora-backup.timer
```

## Çfarë ruhet

- dump MySQL me transaction konsistente;
- `storage/app/private` (dokumente private të tenant-ëve);
- `storage/app/public` (logo dhe imazhe);
- snapshot PII-free me numra rekordesh dhe totale financiare;
- SHA-256 për dump-in dhe snapshot-in.

Script-i dështon pa upload nëse integriteti multitenant nuk kalon, dump-i është
bosh, config-u lexohet nga përdorues të tjerë ose një backup tjetër është në punë.

## Restore drill i detyrueshëm

Restore-i nuk bëhet mbi production. Përdoret një host ose container i izoluar:

1. `restic check --read-data` për lexim të plotë të repository-t.
2. `restic restore latest --tag lora-production --target /restore/lora`.
3. Verifiko `sha256sum -c SHA256SUMS` në folderin e run-it të restauruar.
4. Krijo databazë MySQL testuese bosh dhe importo `database.sql`.
5. Konfiguro një checkout të të njëjtit commit kundrejt DB-së së restauruar.
6. Ekzekuto `php artisan tenants:verify-integrity --snapshot=/tmp/before.json`.
7. Ekzekuto migrimet kandidate me `php artisan migrate --force`.
8. Ekzekuto `php artisan tenants:verify-integrity --compare=/tmp/before.json --allow-additive-schema --allow-additive-settings`.
   Kjo lejon vetëm tabela të reja, rritje të permissions dhe settings të reja
   për tenantët ekzistues; fshirjet dhe totalet financiare duhet të mbeten identike.
9. Kontrollo manualisht rezervime, pagesa, financë, POS dhe skedarë.
10. Fshi ambientin testues dhe regjistro datën/rezultatin e drill-it.

Restore drill-i është porta kryesore e sigurisë. Hapi 9 mbyllet pasi, përveç këtij
testi, të aktivizohen replica immutable dhe alarmi i jashtëm për dështimet.

## Restore drill — 2026-07-14

- Snapshot-i Restic `182c2066` u lexua plotësisht me `restic check --read-data`.
- SHA-256 i dump-it dhe metadata-s kaloi pa gabime.
- Dump-i production u importua në MySQL 8.4 të izoluar.
- Kontrolli i integritetit kaloi para migrimeve.
- Të gjitha migrimet e branch-it u aplikuan mbi kopjen production.
- Krahasimi me `--allow-additive-schema` konfirmoi se numrat dhe totalet financiare
  ekzistuese nuk ndryshuan; u lejuan vetëm tabelat dhe permissions e reja.
- Container-i, kopja me PII dhe secrets e përkohshme u fshinë pas testit.

Timer-i production është aktiv dhe ekzekutohet çdo natë rreth orës 02:38
`Europe/Tirane`. Gjendja kontrollohet me:

```bash
sudo systemctl status lora-backup.timer
sudo cat /var/lib/lora-backup/last-success
```
