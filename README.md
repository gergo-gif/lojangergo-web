# lojangergo.com

Statikus oldal + egy kis PHP-végpont a teszthez. A `main` ágra feltöltött változást a Hostinger automatikusan kiteszi a `public_html`-be.

## Titkos beállítások

A kulcsok **nem** ebben a tárolóban vannak. A Hostingeren, a `public_html` **melletti** mappában (egy szinttel feljebb) kell lennie egy `lojangergo-config.php` fájlnak:

```php
<?php
return [
    'brevo_api_key' => 'IDE_A_BREVO_API_KULCS',
    'list_id'       => 0,            // a Brevo-lista azonosítója (szám)
    'sender_email'  => 'hello@lojangergo.com',
    'sender_name'   => 'Loján Gergő',
    'trening_list_id' => 0,          // a tréning-előregisztrációs Brevo-lista azonosítója
    'notify_email'  => 'hello@lojangergo.com', // ide jön az értesítő minden előregisztrációról
];
```

## Teszt

- `teszt/index.html`: a 10 kérdés és a pontozás (a 10. kérdés 2 pont, döntetlennél ez dönt)
- `api/teszt-eredmeny.php`: feliratkoztat a Brevo-listára, és elküldi az eredménylevelet
- `private/teszt-eredmenyek.php`: a négy eredmény szövege (Szél, Parázs, Mélyvíz, Fa)

A Brevo-mezőket (`ELEM`, `MASODIK_ELEM`, `TESZT_DATUM`) az első beküldés automatikusan létrehozza. Hírlevél-listára csak az kerül, aki az első pipát bejelöli.

## Tréning-előregisztráció

- `api/trening-elore.php`: a `trening_list_id` listára teszi a jelentkezőt (mezők: `MUHELY`, `FORMA`, `ELOREG_DATUM`, automatikusan jönnek létre), és értesítőt küld a `notify_email` címre. Ha a lista még nincs beállítva, csak az értesítő megy ki, így senki nem vész el.
