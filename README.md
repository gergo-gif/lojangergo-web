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
];
```

## Teszt

- `teszt/index.html`: a 10 kérdés és a pontozás (a 10. kérdés 2 pont, döntetlennél ez dönt)
- `api/teszt-eredmeny.php`: feliratkoztat a Brevo-listára, és elküldi az eredménylevelet
- `private/teszt-eredmenyek.php`: a négy eredmény szövege (Szél, Parázs, Mélyvíz, Fa)

A Brevóban szükséges kapcsolat-attribútumok: `FIRSTNAME`, `ELEM` (szöveg), `MASODIK_ELEM` (szöveg), `TESZT_DATUM` (dátum).
