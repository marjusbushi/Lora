# Plani i hardening-ut multitenant

## Vendimi arkitekturor

Lora PMS do të përdorë një aplikacion dhe një databazë të përbashkët. Çdo rekord operacional i përket detyrimisht një hoteli përmes `tenant_id`.

- Villa Mucho mbetet tenant-i ekzistues dhe ruan të gjitha të dhënat aktuale.
- Hotelet e reja përdorin të njëjtin aplikacion, por nuk mund të lexojnë ose ndryshojnë të dhënat e njëri-tjetrit.
- Puna zhvillohet në `feat/multitenant-hardening`; `main` dhe production nuk preken gjatë zhvillimit.

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
- [ ] 2. Commands manuale tenant-aware dhe `fail-closed` — **në punë**.
- [ ] 3. Jobs, cache, locks dhe storage tenant-aware.
- [ ] 4. DB: `tenant_id NOT NULL` dhe kontrolle same-tenant.
- [ ] 5. Handoff i sigurt Control Panel → custom domain.
- [ ] 6. Teste Hotel A/B për modulet kryesore.
- [ ] 7. MySQL fresh/upgrade dhe kontroll integriteti.
- [ ] 8. CI para deploy-it dhe branch protection.
- [ ] 9. Backup off-server dhe provë restore.
- [ ] 10. Integrim me translations, staging pilot dhe aprovim për `main`.

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

## Kushtet e përfundimit

- Hotel A nuk mund të lexojë, krijojë, ndryshojë ose fshijë të dhënat e Hotel B.
- Asnjë rekord tenant nuk ka `tenant_id = NULL`.
- Komandat dhe jobs pa tenant dështojnë pa prekur të dhëna.
- Backup/restore është provuar me sukses.
- CI dhe testet A/B janë green.
- Numrat e rekordeve dhe totalet financiare të Villa Mucho mbeten të pandryshuara.
- Kalimi në `main` bëhet vetëm pas aprovimit eksplicit.
