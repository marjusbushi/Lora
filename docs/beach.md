# Moduli "Plazhi" — udhëzues për stafin

Ky udhëzues mjafton që një recepsionist i ri të bëjë rezervimin e parë të çadrës pa pyetur njeri.

## 1. Si aktivizohet moduli

Moduli **Plazhi** është pjesë e paketës së hotelit (entitlement `beach`). Kur është aktiv, në menunë anësore të PMS-it shfaqet zëri **Plazhi** me dy nën-faqe: **Kalendari i çadrave** dhe **Harta & Çmimet**, dhe në webin publik të hotelit shfaqet linku **Shezllonet**. Nëse s'i shihni, moduli s'është aktiv për hotelin tuaj — kontaktoni administratorin e Lora PMS.

## 2. Setup-i i parë (bëhet një herë nga pronari/admini)

1. Hapni **Plazhi → Harta & Çmimet**.
2. Klikoni **Shto zonë** — shkruani emrin (p.sh. "Rreshti 1"), **Çmimi / ditë** dhe ruajeni me **Krijo**. Krijoni një zonë për çdo rresht të plazhit.
3. Te çdo zonë klikoni **Gjenero çadra** — shkruani sa çadra doni te **Sa çadra?** dhe klikoni **Gjenero**. Numrat vazhdojnë vetë nga numri më i lartë i plazhit (zona e dytë s'përplaset kurrë me të parën).
4. Klikoni **Printo QR-të** — printohet një fletë A4 me numrin e madh + QR për çdo çadër. Priteni, plastifikojeni dhe ngjiteni një QR në shkopin e çdo çadre. **QR-ja është e përjetshme** — mos e riprintoni kur të vijnë veçori të reja.
5. Hapni **Cilësimet → Plazhi** (tab-i "Plazhi" te Settings):
   - **Sa ditë përpara lejohet rezervimi online?** — p.sh. 10 = klientët rezervojnë nga sot deri 10 ditë përpara. Recepsioni s'ka kufi.
   - **Fillimi / Mbyllja e sezonit** — jashtë këtyre datave klientët s'rezervojnë dot online. Bosh = plazhi i hapur gjithë vitin.
   - Ruani me **Ruaj cilësimet**.

## 3. Puna e përditshme e recepsionit

Gjithçka bëhet te **Plazhi → Kalendari i çadrave** (rreshtat = çadrat, kolonat = ditët; ndryshoni pamjen me **7d / 14d / 30d** dhe lëvizni me shigjetat ose **Sot**).

- **Rezervim nga telefonata:** tërhiqni miun mbi ditët e çadrës që kërkon klienti (ose klikoni **Rezervim i ri**). Në dritaren që hapet janë gati çadra dhe datat; shkruani **Emrin e klientit** dhe **Telefonin**, zgjidhni statusin — **I konfirmuar** (gati) ose **Në pritje** (po pret konfirmim, pa dyfish punë më vonë) — dhe klikoni **Krijo**. Çmimi llogaritet vetë: ditë × çmimi i zonës.
- **Zgjatje / ndryshim:** klikoni mbi shiritin e rezervimit → ndryshoni datat, çadrën apo statusin → **Ruaj**. Nëse çadra është e zënë në datat e reja, sistemi ju ndalon me mesazh — zero double-booking.
- **Anullim:** klikoni mbi shiritin → **Anullo rezervimin**. Çadra lirohet menjëherë.
- Rezervimet nga webi hyjnë vetë në kalendar me shënimin **Nga webi** dhe status **Në pritje** — konfirmojini kur klienti paguan në plazh.

## 4. Çfarë sheh klienti (webi publik)

Klienti hap webin e hotelit → **Shezllonet** (ose skanon QR-në e një çadre në plazh):

1. Zgjedh datat — datat jashtë dritares së rezervimit ose jashtë sezonit janë të mbyllura që në kalendar.
2. Klikon **Shiko çadrat e lira** → sheh hartën e plazhit: deti sipër, çadrat me nga dy shezllone, rrugicat mes rreshtave. Të zënat janë gri dhe s'klikohen dot.
3. Zgjedh çadrën → sheh totalin (ditë × çmim) → **Vazhdo** → shkruan emrin + telefonin → **Rezervo**.
4. Merr faqen e konfirmimit me **QR** — e tregon te stafi në plazh. Pagesa bëhet **në plazh** (cash/kartë), jo online.

## 5. Kufizimet e V1 (çfarë vjen më vonë)

- **Pa pagesë online** — rezervimi është "paguaj në vend"; pagesa me kartë online vjen me integrimin POK (proposal #19).
- **Pa porosi bari nga QR** — në V2 e njëjta QR e çadrës do hapë menunë e barit dhe porosia shkon direkt te POS me numrin e çadrës.
- **Pa email konfirmimi** — klienti ka vetëm faqen/QR-në e konfirmimit.
- Çadra me historik rezervimesh **s'fshihet** — çaktivizojeni te Harta & Çmimet (hiqet nga shitja, historiku ruhet).
