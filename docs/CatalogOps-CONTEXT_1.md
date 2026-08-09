# CatalogOps — kontekst projekta

*Dokument za Project knowledge. Verzija 2.0 · avgust 2026.*
*Status: okruženje verifikovano, spremni za prvi kod.*

Sve što je potrebno da se nastavi rad bez ponavljanja prethodnih razgovora. Ako se odluka menja, menja se **ovde**.

---

## 1. Šta gradimo

**CatalogOps** (radni naziv) — komercijalni WooCommerce plugin za masovne operacije nad katalogom proizvoda, za prodaju po pretplatničkom modelu.

**Misija:** masovne izmene kataloga ne smeju biti rizičan potez.

**Marketinška rečenica:** *„Izmeni 20.000 proizvoda. Vidi šta će se promeniti pre nego što se promeni. Vrati sve jednim klikom."*

### Zašto baš ovo

Razmatrano je desetak kategorija. Feature-plugin kategorije (quote button, back-in-stock, min/max, file upload) odbačene: plafon cene ~50 USD, visok churn, nula moata — incumbent sa 90.000 instalacija ih doda kao checkbox i posao nestane.

Bulk editor izabran jer: kupac je agencija ili distributer (ne cenovno osetljiv hobista), bol je svakodnevan i merljiv u satima, **tehnička težina je barijera za ulazak a ne mana**, i konkurencija postoji ali sve pada na istim mestima.

### Šta konkurencija radi loše (naša prilika)

Smart Manager, WP Sheet Editor, WOOBE, Bulk Table Editor:

1. Timeout na velikim katalozima ostavlja **polovično izvršenu operaciju** — bez greške u logu.
2. **Nema undo.** Pogrešan filter prepiše 3.000 cena; povratak samo kroz restore baze.
3. **Nema preview / dry-run.**
4. **Varijacije su drugorazredne** — a to je razlog zbog kog ljudi traže alat.
5. **Meta iz drugih plugin-ova nedostupna** (ACF, WPML, brendovi, custom polja tema).
6. **Nema audit loga.**

### Šta proizvod NIJE

- Nije import/export plugin. Radimo sa podacima koji su već u bazi.
- Nije alat za narudžbine. V1 dira **isključivo proizvode i varijacije**.
- Nije zamena za backup.
- Nije alat za početnike.
- Nije vizuelni uređivač. Tabela, filter, akcija.

---

## 2. Arhitektonski principi

Šest pravila za svaku liniju koda. Ako funkcija zahteva kršenje bilo kog — funkcija se ne pravi.

1. **Jedan pipeline.** `Filter → Preview → Snapshot → Izvršenje → Verifikacija`. Kroz njega prolazi sve: ručne izmene, undo, zakazane operacije, CLI.
2. **Lista ciljeva se zamrzava pre izvršenja.** Filter se izvršava tačno jednom. **Nikad paginacija nad živim upitom** — ako menjaš cenu a filtriraš po ceni, izmenjeni objekti ispadaju iz rezultata i tiho preskočiš deo kataloga.
3. **Ništa sinhrono.** Sve piše kroz Action Scheduler u chunkovima. Chunk handler je idempotentan.
4. **Svaka izmena se beleži kao delta** (`old_value` / `new_value`). To je istovremeno undo i audit log.
5. **Undo je operacija, ne funkcija.** Inverzna operacija ulazi u isti pipeline, ima preview i sama se može poništiti.
6. **Nikad `eval()`.** Formule kroz sopstveni shunting-yard parser (~200 linija).

---

## 3. Ključne tehničke odluke

### Šema baze — dve custom tabele

```sql
CREATE TABLE {prefix}catalogops_operations (
  id             BIGINT UNSIGNED AUTO_INCREMENT,
  created_at     DATETIME,
  completed_at   DATETIME NULL,
  user_id        BIGINT UNSIGNED,
  status         VARCHAR(20),   -- draft|queued|running|paused|completed|failed|reverted
  source         VARCHAR(20),   -- ui|cli|schedule|undo
  parent_op_id   BIGINT NULL,   -- kod undo operacija
  filter_json    LONGTEXT,
  actions_json   LONGTEXT,
  target_count   INT,
  processed      INT DEFAULT 0,
  failed         INT DEFAULT 0,
  mode           VARCHAR(10),   -- safe|fast
  PRIMARY KEY (id), KEY (status), KEY (created_at)
);

CREATE TABLE {prefix}catalogops_changes (
  id           BIGINT UNSIGNED AUTO_INCREMENT,
  operation_id BIGINT UNSIGNED,
  object_type  VARCHAR(20),   -- product|variation
  object_id    BIGINT UNSIGNED,
  field_type   VARCHAR(20),   -- post_field|meta|term|attribute
  field_key    VARCHAR(191),
  old_value    LONGTEXT,
  new_value    LONGTEXT,
  status       TINYINT,       -- 0 pending, 1 applied, 2 failed, 3 skipped
  PRIMARY KEY (id), KEY op_obj (operation_id, object_id)
);
```

Retencija `changes`: **30 dana** podrazumevano, podesivo 7–180. Cron čišćenje. To je ujedno undo prozor i mora biti eksplicitno u UI.

### Batch engine

Pri `queue` fazi filter se izvrši jednom, svi ID-jevi upišu u `changes` sa `status = 0`. Izvršenje od tada čita **isključivo iz te tabele**.

```php
$ids = $this->query_engine->resolve( $filter );   // jednom, i to je to
$this->changes->seed( $op_id, $ids );             // bulk INSERT, chunk 1000

foreach ( array_chunk( $ids, $this->batch_size ) as $i => $chunk ) {
    as_enqueue_async_action(
        'catalogops_run_chunk',
        [ 'op_id' => $op_id, 'chunk' => $i ],
        'catalogops_' . $op_id   // grupa — omogućava masovno otkazivanje
    );
}
```

Obavezno: **idempotencija** (provera `status = 0` pre izmene, upis `1` u istoj transakciji) · **adaptivni batch size** (chunk preko ~15s → smanji sledeći) · **watchdog** (bez napretka 10 min → `failed` sa opcijom nastavka) · **zaključavanje** (jedna pisajuća operacija po sajtu).

### Undo i detekcija drifta

```php
$current = $provider->read( $object_id, $field_key );

if ( $this->values_equal( $current, $change->new_value ) ) {
    $provider->write( $object_id, $field_key, $change->old_value );
} else {
    match ( $conflict_policy ) {
        'skip'  => $this->mark_skipped( $change ),   // podrazumevano
        'force' => $provider->write( $object_id, $field_key, $change->old_value ),
    };
}
```

`values_equal` tolerantna na tipove — `"19.90"` i `19.9` su ista cena. Strogo poređenje pravi lažne konflikte.

Preview undo-a: *„812 izmena biće vraćeno, 3 preskočeno jer su promenjena nakon operacije."*

### Safe vs fast režim

| | Safe | Fast |
|---|---|---|
| Zapis | `WC_Product` CRUD | direktan `$wpdb` + ciljano čišćenje keša |
| Brzina | ~40–80 obj/s | ~1.500–4.000 obj/s |
| Hukovi | svi | nijedan |
| Rizik | nema | ERP sync, search indeks, WPML ne znaju za izmenu |

**Safe je podrazumevan. Fast ide u v1.1, ne v1.0.**

Posle fast operacije obavezno: `wc_delete_product_transients()` po ID-u, regeneracija `wc_product_meta_lookup`, `wp_cache_delete` za `post_meta` grupu. Bez toga: cene tačne u adminu, pogrešne u shopu.

### Field Provider apstrakcija

```php
interface Field_Provider {
    public function get_fields(): array;
    public function read( int $id, string $key );
    public function write( int $id, string $key, $value ): bool;
    public function query_clause( string $key, string $op, $value ): array;
}
```

Core: `Core_Fields`, `Meta_Fields`, `Taxonomy_Fields`, `Attribute_Fields`.
Moduli (M7): `ACF_Provider`, `WPML_Provider`, `Yoast_Provider`, `Brands_Provider`.

**Javni API od v1** — agencije pišu svoje providere, i to je poenta.

### Formule

Shunting-yard parser. Tokeni: brojevi, imena polja, `+ - * / ( )`, funkcije `round ceil floor roundto min max abs`.

```
regular_price * 1.2
roundto( cost * 1.35, 0.99 )
max( regular_price * 0.8, cost * 1.1 )
```

Prazno ili nenumeričko polje → **preskoči objekat i zabeleži u log**. Nikad kao nula (tako se cene postavljaju na 0).

> **TODO (M5, uz % / formule) — custom fields i atributi varijacija:** kada se u M5 dodaju procentualne/formulske izmene cene (npr. „skini 10% za kategoriju X"), tada rešiti i pitanje **custom polja** u adminu. U M4 su privremeno uklonjena iz bulk-edita i iz filtera (bila su zbunjujuća; brend je tada izdvojen u poseban dropdown). Odluke koje treba doneti u M5:
> - Da li „custom fields" uopšte treba da postoje kao takvi, ili se preformulišu kao **filter po atributu varijacije** (npr. veličina/boja) — što je verovatno prirodnije i **pripada filteru**, ne bulk-editu.
> - Ako ostaju kao proizvoljna meta polja: kako birati ključ i vrednost bez izlaganja internih WooCommerce ključeva (`attribute_*`, `_price`, …) — već postoji `/fields/meta-keys` endpoint kao osnova.
> - Cilj scenarija: agencija filtrira po kategoriji + brendu (+ eventualno atributu varijacije) i primeni procentualnu izmenu cene.

### ⚠ MySQL 5.7 ograničenja

Razvojna baza je 5.7.36. **Ne koristiti:** CTE (`WITH`), window funkcije (`ROW_NUMBER()`, `RANK()`), funkcionalne indekse, `DESC` indekse.

Razvoj na 5.7 je prednost — ako radi ovde, radiće na 8.0. Obrnuto ne važi. **U M6 dodati Docker kontejner sa MySQL 8.0** za verifikaciju upita (optimizator se razlikuje dovoljno da brz upit na 5.7 može biti spor na 8.0).

Deklarisani minimum: **MySQL 5.7 / MariaDB 10.4**.

---

## 4. Faze razvoja

Svaka faza ima definiciju gotovog. Dok nije ispunjena, sledeća ne počinje.

| Faza | Opseg | Gotovo kada | Trajanje |
|---|---|---|---|
| **M0** | Skelet, PSR-4, DI, migracije, CI, generator test kataloga | `wp catalogops seed --products=50000` radi, testovi prolaze na CI | 1 ned. |
| **M1** | Query engine, filter struktura, SQL bez teških JOIN-ova, tabelarni prikaz, sačuvani filteri. **Read-only.** | Filter po ceni, stanju, kategoriji, atributu i meta polju vraća tačan rezultat nad 50.000 proizvoda ispod 2s | 3–4 ned. |
| **M2** | Write engine, snapshot, Action Scheduler, progress UI, watchdog | Izmena cene nad 20.000 proizvoda prolazi do kraja uz `max_execution_time = 30`, lookup tabele osvežene | 3–4 ned. |
| **M3** | Undo, detekcija drifta, politika konflikata, audit log, retencija | Operacija nad 10.000 proizvoda se poništava, drift korektno preskočen i prikazan | 2–3 ned. |
| **M4** | Varijacije kao prvoklasni objekat | 50.000 varijacija filtrirano i izmenjeno bez punih objekata u memoriji | 3 ned. |
| **M5** | Parser formula, zakazane i ponavljajuće operacije, obaveštenja | `roundto(cost * 1.35, 0.99)` radi, prazna polja preskočena uz log, zakazana operacija šalje izveštaj | 2–3 ned. |
| **M6** | Licenciranje, auto-update, multisite, onboarding, dokumentacija, prevodi, MySQL 8.0 verifikacija | Neko ko nas nikad nije video izvede prvu operaciju bez objašnjenja | 2 ned. |
| **M7** | Javni Field_Provider API, ACF/WPML/brands moduli, WP-CLI | Nezavisni developer napiše provider prateći samo dokumentaciju | 2 ned. |

**Ukupno do v1.0: 18–22 nedelje.**

> **M3 se ne skraćuje ni po koju cenu.** Undo je razlog zbog kog nas biraju.

### Lansiranje

- Nedelja 14 — privatna beta: 5–10 agencija sa katalozima preko 10.000 proizvoda, doživotna Agency licenca za testiranje.
- Nedelja 18 — besplatna verzija na WordPress.org, cilj 500 instalacija.
- Nedelja 22 — komercijalno lansiranje, 40% popusta prvih 14 dana.

Bugovi u ovoj kategoriji se ne nalaze na demo podacima. Nalaze se na katalogu koji je pet godina prolazio kroz četiri različita import plugin-a.

---

## 5. Poslovni model

| Plan | Sajtova | Cena/god | Napomena |
|---|---|---|---|
| Solo | 1 | 99 USD | samo core provideri |
| Studio | 5 | 199 USD | + ACF/WPML moduli |
| Agency | 25 | 399 USD | primarni segment |
| Unlimited | ∞ | 699 USD | |

- Obnova sa 30% popusta prve godine, puna cena kasnije. **Doživotne licence se ne prodaju.**
- **Kanal: Freemius.** Razlog: merchant of record — obračunavaju i prijavljuju EU VAT i US sales tax. Iz Srbije to ne želiš sam da rešavaš. EDD daje veću maržu ali prebacuje poresku obavezu — revidirati pri ~50k USD ARR.
- **Ne CodeCanyon** — gubi se kontakt sa kupcem, cena pada na ~39 USD.
- Besplatna verzija: max 200 objekata po operaciji, bez undo, formula i zakazivanja. Levak, ne osakaćen proizvod.

**Primarni kupac:** agencija sa 10–50 Woo sajtova; naplaćuje ovaj rad klijentu.
**Sekundarni:** uvoznik/distributer sa 2.000–50.000 SKU.
**Ne ciljamo:** dropshipping i POD — cenovno osetljivi, visok support, niska retencija.

---

## 6. Razvojno okruženje — VERIFIKOVANO

WAMP na Windows 11.

| Komponenta | Verzija | Status |
|---|---|---|
| PHP | 8.1.29 (ZTS, x64) | ✅ Xdebug isključen |
| MySQL | 5.7.36 | ✅ |
| Apache | 2.4.66.3 | ✅ |
| WordPress | 7.0.3 | ✅ |
| WooCommerce | 11.0.0 | ✅ aktivan |
| Query Monitor | 4.0.7 | ✅ aktivan |
| WP-CLI | 2.12.0 | ✅ `wp db query` radi |
| Composer | 2.5.1 | ✅ |
| Node | v20.19.0 | ✅ |

### Putanje

```
WP root:     C:\wamp64\www\catalog-ops.app_08.2026
URL:         http://dev.lcl.catalog-ops.wrk/
Plugin repo: C:\dev\catalogops          ← ovde se radi
Simlink:     wp-content\plugins\catalogops → C:\dev\catalogops
PHP CLI ini: C:\wamp64\bin\php\php8.1.29\php.ini
CA bundle:   C:\wamp64\certs\cacert.pem
```

PATH sadrži: `C:\wamp64\wp-cli`, `C:\wamp64\bin\php\php8.1.29`, `C:\wamp64\bin\mysql\mysql5.7.36\bin`

### Namerno pogoršane postavke

Razvoj mora da liči na prosečan shared hosting, inače M2 pada kod prvog kupca.

```ini
; php.ini
max_execution_time = 30
memory_limit = 256M
max_input_vars = 3000
xdebug.mode = "off"
curl.cainfo = "C:\wamp64\certs\cacert.pem"
openssl.cafile = "C:\wamp64\certs\cacert.pem"
```

```php
// wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'SAVEQUERIES', true );      // isključiti pri merenju
define( 'DISABLE_WP_CRON', true );
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '256M' );
```

### ⚠ Specifičnosti okruženja — pročitati pre debagovanja

**1. Avast presreće HTTPS.** Sertifikat izdaje `CN=Avast Web/Mail Shield Root`. Rešeno na dva mesta:
- Avast root dodat u `C:\wamp64\certs\cacert.pem` (backup: `.bak`)
- MU plugin `wp-content/mu-plugins/dev-ssl.php` isključuje WP SSL verifikaciju

Ovi filteri **moraju** biti u mu-pluginu, ne u `wp-config.php` — tamo `add_filter()` još ne postoji i dobiješ fatal error.

`cacert.pem` sa Avast rootom **nikad ne ide u repo ni na server.**

**2. Svi PHP fajlovi: UTF-8 BEZ BOM-a.** PowerShell `Out-File -Encoding utf8` dodaje BOM i plugin ne može da se aktivira („The plugin generated unexpected output"). Koristi:
```powershell
[System.IO.File]::WriteAllText($path, $code, (New-Object System.Text.UTF8Encoding($false)))
```
U VS Code: `"files.encoding": "utf8"` (ne `utf8bom`).

**3. `xdebug.mode = off` bez navodnika** PHP tumači kao boolean false i `ini_get()` vraća prazan string. Koristi `"off"`. Prava provera je merenje:
```powershell
Measure-Command { php -r "for(`$i=0;`$i<3000000;`$i++){`$x=sqrt(`$i);}" }
```
Referentna vrednost: **132ms** sa isključenim Xdebug-om. Preko 1s znači da je uključen.

**4. `wp --info` prazna MySQL polja su normalna na Windows-u** — WP-CLI koristi `which` za detekciju. Prava provera: `wp db query "SELECT VERSION();"`.

**5. WP-Cron je isključen**, a Action Scheduler zavisi od njega. `run-scheduler.bat`:
```bat
@ECHO OFF
cd /d C:\wamp64\www\catalog-ops.app_08.2026
wp action-scheduler run --batch-size=25
```
Windows Task Scheduler, ponavljanje svakih 1 min. **Bez ovoga ćeš na M2 gledati operaciju zaglavljenu na `pending` i tražiti bug koji ne postoji.**

**6. PowerShell nema `cd /d`** (to je CMD). U `.bat` fajlovima ostaje.

### MySQL — otvorena stavka

Trenutno podrazumevana konfiguracija. **Pre M1 merenja podići u `my.ini`:**
```ini
innodb_buffer_pool_size = 512M   ; ili 1G ako RAM dozvoljava
max_allowed_packet = 64M
innodb_flush_log_at_trx_commit = 2   ; SAMO lokalno, ubrzava seed
```
Sa podrazumevanih 128M, `postmeta` na 50.000 proizvoda ne staje u memoriju i merenja su besmislena — optimizovao bi pogrešnu stvar.

### Struktura repozitorijuma

Repo sadrži **samo plugin folder**.

```
catalogops/
├── catalogops.php          # bootstrap, header, aktivacija
├── composer.json
├── package.json
├── src/
│   ├── Plugin.php
│   ├── Container/
│   ├── Database/
│   │   ├── Schema.php
│   │   └── Migrations/
│   ├── Query/              # M1
│   ├── Operations/         # M2–M3
│   ├── Providers/
│   ├── Admin/
│   └── CLI/
├── assets/src/
├── assets/dist/            # .gitignore
├── tests/
│   ├── Unit/
│   └── Integration/
└── .github/workflows/ci.yml
```

`.gitignore`: `vendor/`, `node_modules/`, `assets/dist/`, `.env`, `*.log`, `.phpunit.result.cache`

---

## 7. Zatvorene odluke

| # | Pitanje | Odluka |
|---|---|---|
| 1 | Minimalna PHP verzija | **8.1** — enumi (`OperationStatus`, `FieldType`, `ConflictPolicy`), readonly properties. Razvija se na tačno toj verziji. |
| 2 | Admin UI stack | **React + `@wordpress/scripts`** — jedini stack za tabelu sa 50 kolona, virtuelizovan skrol, live progress |
| 3 | Kanal prodaje | **Freemius** — merchant of record, rešava EU VAT i US sales tax |
| 4 | Fast režim | **v1.1**, ne v1.0 — udvostručuje površinu za bugove tačno u fazi izgradnje reputacije |
| 5 | Retencija delta zapisa | **30 dana**, podesivo 7–180 |
| 6 | ACF/WPML moduli | **Uključeni u Studio i više.** Solo dobija samo core providere — logičan razlog za nadogradnju |
| 7 | Ime proizvoda i domen | **OTVORENO.** Kandidati: BulkForge, CatalogKit, Massfold, StockPilot, Reforge. `CatalogOps` ostaje interni naziv. Rok: pre M6 |

---

## 8. Trenutni status

**Faza: M0 — okruženje i repo gotovi, kod nije počet.**

Urađeno: WAMP verifikovan, WooCommerce i Query Monitor aktivni, simlink radi, CA bundle rešen, Xdebug isključen, Git repo povezan i push-ovan.

Postoji samo `catalogops.php` sa plugin header-om (v0.0.1, aktivan u WP-u). **Nijedna linija funkcionalnog koda.**

### Sledeći koraci

1. `composer.json` + bootstrap + PSR-4 skelet + DI kontejner
2. `Schema.php` i migracije — obe tabele iz sekcije 3
3. WP-CLI komanda `catalogops seed` — generator test kataloga (500 / 5.000 / 50.000)
4. Podići `innodb_buffer_pool_size` pre prvog merenja

Tek nakon toga M1 — bez 50.000 proizvoda u bazi nemamo na čemu da merimo.

### Repozitorijum — POVEZAN ✅

```
URL:      https://github.com/dakakiki/catalog-ops
lokalno:  C:\dev\catalogops
grana:    main (prati origin/main)
```

Commit istorija:
```
b3f8440  chore: normalize line endings
af0c44e  chore: plugin skeleton and gitignore
```

Postojeći fajlovi: `catalogops.php` (samo header, v0.0.1), `.gitignore`, `.gitattributes`.

Repo je javan i sadrži **samo plugin folder**, ne WordPress instalaciju.

Line endings: `.gitattributes` forsira LF za sav kod, CRLF samo za `.bat` i `.ps1`. `core.autocrlf = false`. Ovo je bitno zbog CI-ja na Linuxu i PHPCS provera.

Napomena: lokalni folder je `catalogops`, repo je `catalog-ops` — različita imena su u redu, jer se simlink i plugin slug oslanjaju na lokalni folder.

Ako Git padne na SSL zbog Avast presretanja:
```powershell
git config --global http.sslCAInfo "C:\wamp64\certs\cacert.pem"
```

---

## 9. Rizici

| Rizik | Odgovor |
|---|---|
| Korisnik uništi katalog i okrivi nas | Undo, obavezan backup podsetnik pri prvoj operaciji, preview kao neizbežan korak |
| Timeout ostavlja polovičnu operaciju | Snapshot + idempotentni chunkovi + watchdog |
| Nekompatibilnost sa popularnim plugin-ovima | Safe režim podrazumevan, matrica testiranja sa 15 najčešćih |
| Precenjena brzina razvoja | Definicija gotovog po fazi; M3 se ne skraćuje |
| Support raste brže od prihoda | Ne ciljati dropshipping; dijagnostički izveštaj u jednom kliku |
| `wc_get_products()` nad 10k objekata ubija PHP | U query engine-u samo `SELECT ID` preko `$wpdb` |
| WPML: prevodi su zasebni postovi | Eksplicitna opcija „primeni na sve prevode" |
| Upit brz na 5.7, spor na 8.0 | Docker verifikacija u M6 |

---

## 10. Kako radimo

- Fazu po fazu prema sekciji 4. Ne preskačemo.
- Pre svake faze proveravamo definiciju gotovog prethodne.
- Odluke iz sekcija 3 i 7 su donete i ne preispituju se bez razloga — ako se menjaju, menja se ovaj dokument.
- Kad se pojavi ideja van opsega, proverava se „Šta proizvod NIJE".
- Komunikacija je preko koda u repou i tekstualnog izlaza komandi — lokalna adresa nije dostupna spolja. Za `EXPLAIN` analizu koristi `wp db query "EXPLAIN SELECT ..." --skip-column-names`.
