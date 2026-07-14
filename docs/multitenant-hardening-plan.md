# Plani i hardening-ut multitenant

## Vendimi arkitekturor

Lora PMS do të përdorë një aplikacion dhe një databazë të përbashkët. Çdo rekord operacional i përket detyrimisht një hoteli përmes `tenant_id`.

- Villa Mucho mbetet tenant-i ekzistues dhe ruan të gjitha të dhënat aktuale.
- Hotelet e reja përdorin të njëjtin aplikacion, por nuk mund të lexojnë ose ndryshojnë të dhënat e njëri-tjetrit.
- Hardening-u zhvillohet në `feat/multitenant-hardening` dhe integrohet me staging në `release/multitenant-staging`; `main` dhe production nuk preken pa kaluar portat e sigurisë.

## Plani i zbatimit

1. Izolim fail-closed për çdo model tenant dhe ndalim i ndryshimit të `tenant_id`.
2. Tenant-aware për commands, jobs, cache, locks dhe storage.
3. Migrime DB që e bëjnë `tenant_id` `NOT NULL` dhe kontrollojnë lidhjet brenda të njëjtit tenant.
4. Handoff njëpërdorimësh nga Lora Control Panel te domain-i i hotelit.
5. Teste Hotel A/Hotel B për rezervime, dhoma, mysafirë, finance, POS, inventar, mirëmbajtje, skedarë, role dhe integrime.
6. Verifikim me SQLite, MySQL fresh migration dhe MySQL upgrade migration.
7. CI para deploy-it, branch protection, secure cookies, backup dhe provë restore.
8. Integrim me punën e përkthimeve, testim në staging dhe kalim në `main` vetëm pas aprovimit.

## Todo aktive

- [x] 1. Izolim `fail-closed` për leximet dhe `tenant_id` i pandryshueshëm.
- [x] 2. Commands manuale tenant-aware dhe `fail-closed`.
- [x] 3. Jobs, cache, locks dhe storage tenant-aware.
- [x] 4. DB: `tenant_id NOT NULL` dhe kontrolle same-tenant.
- [x] 5. Handoff i sigurt Control Panel → custom domain.
- [x] 6. Teste Hotel A/B për modulet kryesore.
- [x] 7. MySQL fresh/upgrade dhe kontroll integriteti.
- [x] 8. CI para deploy-it dhe branch protection.
- [ ] 9. Backup off-server dhe provë restore — **në punë**.
  - [x] 9.1. Ofruesi: Backblaze B2 EU me Restic dhe Object Lock.
  - [ ] 9.2. Bucket aktiv privat me Object Lock pa retention default + bucket i dytë immutable me 30 ditë `compliance`; Cloud Replication `lora-prod-immutable-30d` është aktive dhe backup-i i ri u verifikua. Skedarët ekzistues replikohen pas mesnatës UTC dhe duhen numëruar para mbylljes.
  - [x] 9.3. Application Key i kufizuar vetëm te bucket-i, secrets `root:root 0600` jashtë kodit dhe fjalëkalimi i rikuperimit i ruajtur privatisht në Zoho Vault.
  - [ ] 9.4. Backup automatik i DB + storage, kontroll integriteti dhe alarm në dështim — timer-i production është aktiv; alarmi i jashtëm mbetet për t'u lidhur.
  - [x] 9.5. Restore real në MySQL 8.0.46 të izoluar, upgrade + rollback mbi kopjen production dhe krahasim i saktë i 64 tabelave — kaloi më 2026-07-15.
- [ ] 10. Integrim me translations, staging pilot dhe aprovim për `main`.
  - [x] 10.1. Branch-i i release-it u krijua nga staging-u më i fundit dhe translations u integruan pa konflikte.
  - [x] 10.2. Build-i, testet lokale dhe auditimi i varësive kaluan (`0` vulnerabilitete npm).
  - [x] 10.3. Pull Request drejt `staging` dhe tre kontrollet CI: `application`, `mysql-migrations`, `mysql-upgrade` — PR #74 kaloi më 2026-07-14.
  - [ ] 10.4. Smoke test dhe pilot i izoluar në staging.
  - [ ] 10.5. Aprovim eksplicit para release-it në `main`.
  - [x] 10.6. Rehearsal i rollback-ut: skema dhe checksum-et e 64/64 tabelave identike; kodi i vjetër u ngrit pa migrime pending.

### Arkitektura e backup-it off-server

- Aplikacioni dhe databaza aktive mbeten në Hetzner; në Backblaze ruhet vetëm kopja rezervë e enkriptuar.
- Bucket-i duhet të jetë privat, në rajonin EU dhe i dedikuar vetëm për Lora PMS.
- Bucket-i aktiv i Restic ka Object Lock të aktivizuar, por nuk ka retention default: Backblaze paralajmëron se retention-i default në repository-n aktiv mund të ndërhyjë në mirëmbajtjen e Restic.
- Për immutability përdoret një bucket i dytë me retention `compliance`, ku të dhënat kopjohen me Cloud Replication; kjo ruan repository-n aktiv funksional dhe replica-n të pafshirshme.
- Application Key kufizohet vetëm te ky bucket. Kredencialet dhe fjalëkalimi i Restic nuk ruhen në git, chat ose `.env.example`.
- Backup-i konsiderohet i vlefshëm vetëm pasi një restore real të kalojë kontrollin `tenants:verify-integrity`.

## Porta e sigurisë për Villa Mucho

Asnjë migrim multitenant nuk ekzekutohet në production pa kaluar të gjitha pikat:

> **Shënim i detyrueshëm:** Backup i databazës dhe storage-it → kopje jashtë serverit → restore real në ambient testues → provë e migrimeve mbi kopjen më të fundit të production-it → krahasim i rezervimeve, rekordeve dhe totalit financiar para/pas → vetëm pastaj production.

1. Backup i plotë i databazës dhe storage-it të Villa Mucho.
2. Backup-i kopjohet jashtë serverit të production-it.
3. Kryhet restore real i backup-it në një databazë të veçantë testimi.
4. Migrimet provohen mbi kopjen e fundit të databazës reale, jo vetëm mbi databazë bosh.
5. Krahasohen para/pas numrat e rekordeve dhe totalet financiare kryesore.
6. Kontrollohet që çdo rekord operacional ka `tenant_id` e Villa Mucho dhe nuk ka vlera `NULL`.
7. Përgatitet rollback i kodit; migrimet destruktive nuk lejohen në këtë fazë.
8. Deploy-i bëhet me maintenance mode dhe me ndalim të përkohshëm të queue/scheduler kur kërkohet.
9. Pas deploy-it ekzekutohet smoke test për rezervime, pagesa, finance, POS dhe website.
10. Villa Mucho monitorohet para se të krijohet tenant-i i dytë real.

## Kontrolli para/pas migrimit

Snapshot-i përmban vetëm numra rekordesh dhe totale financiare sipas tenant-it; nuk ruan PII ose kredenciale.

```bash
php artisan tenants:verify-integrity --snapshot=/path/secure/lora-before.json
php artisan migrate --force
php artisan tenants:verify-integrity --compare=/path/secure/lora-before.json
```

Nëse ka `tenant_id` të pavlefshëm, lidhje cross-tenant, role pa `team_id`, ndryshim numrash ose ndryshim totalësh financiarë, komanda dështon dhe deploy-i ndalet.

## Mbrojtja e degëve dhe deploy-it

- `main` dhe `staging` pranojnë ndryshime vetëm me Pull Request.
- Kërkohen `application`, `mysql-migrations` dhe `mysql-upgrade`, me branch-in të përditësuar.
- Rregullat vlejnë edhe për administratorin; force-push dhe fshirja e degës janë të ndaluara.
- Kërkohet histori lineare dhe zgjidhja e bisedave të PR-it.
- Meqë repo ka një maintainer, approval-i i jashtëm është `0`; sapo të ketë maintainer të dytë bëhet `1`.
- Production deploy nis vetëm pasi workflow `Tests` në `main` përfundon me sukses.

## Kushtet e përfundimit

- Hotel A nuk mund të lexojë, krijojë, ndryshojë ose fshijë të dhënat e Hotel B.
- Asnjë rekord tenant nuk ka `tenant_id = NULL`.
- Komandat dhe jobs pa tenant dështojnë pa prekur të dhëna.
- Backup/restore është provuar me sukses.
- CI dhe testet A/B janë green.
- Numrat e rekordeve dhe totalet financiare të Villa Mucho mbeten të pandryshuara.
- Kalimi në `main` bëhet vetëm pas aprovimit eksplicit.
