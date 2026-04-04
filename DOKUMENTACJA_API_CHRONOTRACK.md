# Dokumentacja API ChronoTrack - Przepływ Danych

## Event przykładowy: 89081 (Botaniczna Piątka)

---

## 1. ZAPYTANIA API - PEŁNY PRZEGLĄD

System wykonuje następujące zapytania do API ChronoTrack (baza: `https://api.chronotrack.com`):

### 1.1. GET /api/event/{event_id}
**Cel:** Pobranie podstawowych informacji o evencie

**Parametry:**
```
?format=json
&client_id=727dae7f
&user_id=lukasz@yogoevents.pl
&user_pass=f2b2f3082a9d2ae5091eb5920bee538dc01bb413
```

**Przykładowe zapytanie dla event 89081:**
```
https://api.chronotrack.com/api/event/89081?format=json&client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=...
```

**Zbierane atrybuty:**
- `event_id` - ID eventu
- `event_name` - nazwa eventu
- `event_start_time` - data rozpoczęcia (timestamp)
- `event_date` - alternatywna data
- `location_time_zone` - strefa czasowa (np. "Europe/Warsaw")
- `location_city` - miasto eventu
- `event_is_published` - status publikacji (1/0)

**Gdzie są zapisywane:** 
Tabela `wp_chronotrack_events`
- `event_id`
- `event_name`
- `event_date` (skonwertowana do formatu MySQL Y-m-d H:i:s)
- `timezone`
- `location` (miasto po usunięciu przecinka)
- `status` ('active'/'inactive')

---

### 1.2. GET /api/event/{event_id}/entry
**Cel:** Pobranie danych osobowych uczestników (NAJWAŻNIEJSZE dla nationality/country!)

**Parametry:**
```
?format=json
&client_id=...
&user_id=...
&user_pass=...
&page=1
&size=50
&include_test_entries=true
&elide_json=false
&contact_details=true
&include_all_fields=true
&need_athlete_birthdate=true
```

**Paginacja:** System iteruje przez wszystkie strony (page=1, 2, 3...) aż do `page_count`

**Przykładowe zapytanie:**
```
https://api.chronotrack.com/api/event/89081/entry?format=json&page=1&size=50&include_all_fields=true&...
```

**KLUCZOWE atrybuty dla nationality/country:**
```php
'entry_bib' => numer startowy (klucz do powiązania z results)
'country_name' => nazwa kraju (np. "Poland") ⭐ NAJWAŻNIEJSZE
'country' => kod lub nazwa kraju ⭐
'nationality' => narodowość ⭐
'location_country' => kod kraju (np. "PL") ⭐
'athlete_city' => miasto zawodnika
'location_city' => miasto (alternatywne pole)
'club' => klub
'athlete_club' => klub (alternatywne pole)
'custom_element_*' => pola niestandardowe (mogą zawierać klub)
'athlete_birthdate' => data urodzenia
'reg_transaction_account_birthdate' => data urodzenia (alternatywne)
'race_distance' => dystans
'race_name' => nazwa biegu
'reg_choice_name' => nazwa dystansu
```

**Gdzie są zapisywane:**
Tablica w pamięci: `$entries_by_bib[{bib_number}]`
```php
array(
    'city' => ...,
    'club' => ...,
    'athlete_city' => ...,
    'athlete_club' => ...,
    'location_city' => ...,
    'birthdate' => ...,
    'distance' => ...,
    'race_name' => ...,
    'country_name' => ...,      // ⭐
    'country' => ...,           // ⭐
    'nationality' => ...,       // ⭐
    'location_country' => ...,  // ⭐
)
```

**WAŻNE:** Te dane są pobierane TYLKO w trybie 'full':
- Pierwsze załadowanie strony
- Ręczne odświeżenie (przycisk "Odśwież wyniki")

W trybie 'live' (auto-refresh co 10s) system używa **cached entries z bazy danych**.

---

### 1.3. GET /api/event/{event_id}/results
**Cel:** Pobranie wyników (czasy, pozycje)

**Parametry:**
```
?format=json
&client_id=...
&user_id=...
&user_pass=...
&page=1
&size=100
&include_all_fields=true
&need_athlete_birthdate=true
&need_transaction_account=true
&interval=ALL  (pobiera wszystkie międzyczasy)
```

**Paginacja:** Iteracja przez wszystkie strony (page=1, 2, 3...)

**Przykładowe zapytanie:**
```
https://api.chronotrack.com/api/event/89081/results?format=json&page=1&size=100&interval=ALL&...
```

**Zbierane atrybuty:**
```php
'results_bib' => numer startowy (klucz do powiązania z entries)
'results_first_name' => imię
'results_last_name' => nazwisko
'results_interval_name' => nazwa punktu (np. "PK", "PK2", "Meta", "Full Course", "Finish")
'results_time' => czas w formacie HH:MM:SS
'results_rank' => pozycja w klasyfikacji (dla tego punktu)
'results_pace' => tempo (z API, jeśli dostępne)
'results_pace_unit' => jednostka tempa (min/km, km/h)
'results_gender' => płeć (M/K)
'results_age' => wiek
'results_category' => kategoria wiekowa
'results_hometown' => miejscowość (może zawierać kraj, np. "Września, Poland")
'results_country' => kraj ⭐
'results_country_code' => kod kraju (np. "PL") ⭐
'results_nationality' => narodowość ⭐
'results_primary_bracket_name' => nazwa kategorii (bracket)
'athlete_id' => ID zawodnika (jeśli dostępne)
```

**Jak są przetwarzane:**

System grupuje wyniki według `results_bib`:
- Jeśli `results_interval_name` = "Full Course", "Finish" lub pusty → to jest **main_result** (wynik główny)
- Jeśli `results_interval_name` = "PK", "PK2" itp. → to jest **split_time** (międzyczas)

```php
$all_results_by_bib[$bib] = array(
    'main_result' => ...,  // wynik główny (meta)
    'split_times' => array(
        array('interval_name' => 'PK', 'time' => '08:07', ...),
        array('interval_name' => 'PK2', 'time' => '16:39', ...),
    )
);
```

---

### 1.4. GET /api/event/{event_id}/interval
**Cel:** Pobranie metadanych punktów pośrednich (TYLKO nazwy i dystanse, BEZ wyników!)

**UWAGA:** Ten endpoint NIE zwraca:
- ❌ `bib` (numerów startowych)
- ❌ `interval_time` (czasów na punktach)
- ❌ wyników zawodników

**Czasy międzyczasowe są w:** `/api/event/{event_id}/results` (sekcja 1.3)

**Parametry:**
```
?format=json
&client_id=...
&page=1
&size=50
```

**Zbierane atrybuty (tylko metadane):**
```php
'interval_name' => nazwa punktu (np. "PK", "PK2")
'interval_iv_name' => alternatywna nazwa
'interval_iv_distance_m' => dystans w metrach (np. 5000 dla 5km) ⭐ BARDZO WAŻNE dla tempa
```

**Gdzie są zapisywane:**
```php
$intervals_metadata[$interval_name] = array(
    'distance_m' => 5000,
    'distance_km' => 5.0,
);
```

**Do czego służy:** Obliczanie tempa (pace) na podstawie czasu i dystansu.
**Wyniki z czasami są w:** sekcja 1.3 - `/api/event/{event_id}/results`

---

### 1.5. GET /api/event/{event_id}/bracket
**Cel:** Pobranie listy kategorii (brackets)

**Parametry:**
```
?format=json
&page=1
&size=50
```

**Zbierane atrybuty:**
```php
'bracket_name' => nazwa kategorii (np. "M30", "K40", "Overall")
'bracket_type' => typ (AGE, SEX, PRIMARY)
'bracket_id' => ID kategorii
```

**UWAGA:** Jeśli ten endpoint zwraca 0 wyników, system ekstraktuje brackety z pola `results_primary_bracket_name` w wynikach.

---

### 1.6. GET /api/event/{event_id}/results?results_bracket_name={bracket_name}
**Cel:** Pobranie pozycji w konkretnej kategorii

**Przykład:**
```
/api/event/89081/results?results_bracket_name=M30&page=1&size=100
```

**Zbierane atrybuty:**
```php
'results_bib' => numer startowy
'results_rank' => pozycja w tej kategorii
```

**Gdzie są zapisywane:**
```php
$bracket_results[$bib][$bracket_name] = $position;

// Przykład:
$bracket_results['95']['M30'] = 4;  // zawodnik nr 95 ma 4. miejsce w kategorii M30
```

---

## 2. PRZETWARZANIE DANYCH - COUNTRY/NATIONALITY

### 2.1. Proces w `process_single_result()` (linia ~1089-1135)

System próbuje uzyskać `country` w następującej kolejności:

**PRIORYTET 1:** `entry['country_name']` (z /entry endpoint) ⭐ **NAJLEPSZE ŹRÓDŁO**
```php
$country = $entry['country_name'] ?? '';
```

**PRIORYTET 2:** `entry['country']` (z /entry endpoint)
```php
$country = $entry['country'] ?? '';
```

**PRIORYTET 3:** `result['results_country']` (z /results endpoint)
```php
$country = $result['results_country'] ?? '';
```

**PRIORYTET 4:** Konwersja `entry['location_country']` (kod → nazwa)
```php
// Jeśli entry['location_country'] = "PL"
// To: $country = "Poland"
```

**PRIORYTET 5:** Ekstrakcja z `results_hometown`
```php
// Jeśli results_hometown = "Września, Poland"
// To: $country = "Poland" (ostatnia część po przecinku)
```

**PRIORYTET 6:** Konwersja `results_country_code`
```php
// Jeśli results_country_code = "PL"
// To: $country = "Poland"
```

**PRIORYTET 7:** Detekcja z miasta (`detect_country_from_city()`)
```php
// Jeśli city = "Września", "Poznań", "Warszawa" → $country = "Poland"
// Jeśli city = "Berlin", "München" → $country = "Germany"
```

### 2.2. Mapa kodów krajów (linia 1049-1087)

```php
$country_code_map = array(
    'PL' => 'Poland',
    'DE' => 'Germany',
    'CZ' => 'Czech Republic',
    'SK' => 'Slovakia',
    'UA' => 'Ukraine',
    'LT' => 'Lithuania',
    'BY' => 'Belarus',
    'GB' => 'United Kingdom',
    'US' => 'United States',
    // ... i inne
);
```

### 2.3. Gdzie są zapisywane w bazie danych

Plik: `class-chronotrack-api.php`, linia ~1200-1210

```php
array(
    'bib_number' => ...,
    'first_name' => ...,
    'last_name' => ...,
    'country' => $country,              // ⭐
    'location_country' => $country,     // ⭐ duplikat
    'athlete_country' => $country,      // ⭐ duplikat
    'nationality' => $nationality,      // ⭐
    'athlete_nationality' => $nationality, // ⭐ duplikat
    // ...
)
```

**Tabela:** `wp_chronotrack_results`
**Kolumny:**
- `country` (VARCHAR)
- `nationality` (VARCHAR)

---

## 3. PRZESYŁANIE DO FRONTEND (JavaScript)

### 3.1. AJAX Handler: `chronotrack_get_results`

Plik: `class-chronotrack-ajax.php`, linia 189-218

```php
$formatted[] = array(
    'id' => ...,
    'bib_number' => ...,
    'first_name' => ...,
    'last_name' => ...,
    'full_name' => ...,
    // ... inne pola ...
    'country' => $result->country ?? '',        // ⭐
    'nationality' => $result->nationality ?? '', // ⭐
);
```

**Endpoint AJAX:**
```
POST /wp-admin/admin-ajax.php
action=chronotrack_get_results
event_id=89081
```

**Odpowiedź JSON:**
```json
{
  "success": true,
  "data": {
    "results": [
      {
        "bib_number": "95",
        "first_name": "Adrian",
        "last_name": "Romański",
        "country": "Poland",
        "nationality": "Poland",
        ...
      }
    ],
    "columns": [...],
    "distances": [...],
    "count": 115
  }
}
```

---

### 3.2. JavaScript: Wyświetlanie flag

Plik: `chronotrack-live.js`, linia 510-515

```javascript
// Pobieranie kodu kraju
const countryCode = result.country || result.Country || result.nationality || result.Nationality ||
                  result.athlete_country || result.country_code || result.CountryCode;

let flagEmoji = '';
if (countryCode && typeof CountryFlags !== 'undefined') {
    flagEmoji = CountryFlags.getFlag(countryCode) || '';
}
```

**Problem:** `CountryFlags.getFlag()` oczekuje **KODU kraju** (np. "PL"), ale otrzymuje **NAZWĘ kraju** (np. "Poland")!

**Moduł flag:** `CountryFlags` jest zdefiniowany w `assets/js/country-flags.js`

---

## 4. DLACZEGO FLAGI NIE DZIAŁAJĄ - ANALIZA PROBLEMU

### 4.1. Przekazywane dane

Z bazy danych:
```
country = "Poland"  (nazwa, nie kod!)
nationality = "Poland"
```

Z JavaScript:
```javascript
countryCode = result.country  // = "Poland"
flagEmoji = CountryFlags.getFlag("Poland")  // ❌ NIE ZADZIAŁA
```

`CountryFlags.getFlag()` oczekuje:
```javascript
CountryFlags.getFlag("PL")  // ✅ ZADZIAŁA → 🇵🇱
CountryFlags.getFlag("Poland")  // ❌ NIE ZADZIAŁA → ''
```

### 4.2. Gdzie zapisać kod kraju?

**Opcja 1:** Zapisywać ZARÓWNO kod jak i nazwę

W bazie danych:
- `country` = "Poland" (nazwa dla wyświetlenia)
- `country_code` = "PL" (kod dla flag)

**Opcja 2:** Konwertować nazwę → kod w JavaScript

```javascript
const countryNameToCode = {
    'Poland': 'PL',
    'Germany': 'DE',
    'Czech Republic': 'CZ',
    // ...
};
const countryCode = countryNameToCode[result.country] || result.country;
```

**Opcja 3:** Zmienić `CountryFlags.getFlag()` aby akceptował nazwy

```javascript
getFlag: function(countryInput) {
    // Jeśli długość > 2, to pewnie nazwa → konwertuj
    if (countryInput.length > 2) {
        countryInput = this.nameToCode(countryInput);
    }
    return this.flags[countryInput] || '';
}
```

---

## 5. FLOW DIAGRAM - EVENT 89081

```
┌─────────────────────────────────────┐
│ 1. USER: Odświeża stronę            │
│    https://yogoevents.pl/...89081   │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ 2. FRONTEND: chronotrack-live.js    │
│    init() → loadResults()           │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ 3. AJAX: chronotrack_get_results    │
│    POST admin-ajax.php              │
│    event_id=89081                   │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ 4. PHP: class-chronotrack-ajax.php  │
│    get_results()                    │
│    → db->get_results(89081)         │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ 5. BAZA DANYCH: SELECT * FROM       │
│    wp_chronotrack_results           │
│    WHERE event_id = 89081           │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ 6. WYNIKI: {country: "Poland", ...} │
│    → format_results()               │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ 7. JSON RESPONSE do frontend        │
│    {success: true, data: {...}}     │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ 8. JAVASCRIPT: renderResults()      │
│    countryCode = "Poland"           │
│    CountryFlags.getFlag("Poland")   │
│    → '' (pusta flaga) ❌            │
└─────────────────────────────────────┘
```

---

## 6. QUERY LOG - Rzeczywiste zapytania dla event 89081

### Pierwsze załadowanie (mode='full'):

```
1. GET /api/event/89081
   → Pobiera: event_name, event_date, location

2. GET /api/event/89081/interval?page=1&size=50
   → Pobiera: PK (distance_m: ?), PK2 (distance_m: ?)

3. GET /api/event/89081/entry?page=1&size=50&include_all_fields=true
   → Pobiera: bib=95, country_name="Poland", city="Jędrzejów"
   → Pobiera: bib=11, country_name=?, city="Kraków"
   → ... (wszystkie 115 uczestników)

4. GET /api/event/89081/results?page=1&size=100&interval=ALL
   → Pobiera: bib=95, interval_name="PK", time="08:07", rank=24
   → Pobiera: bib=95, interval_name="PK2", time="16:39", rank=19
   → Pobiera: bib=95, interval_name="Finish", time="25:04", rank=20
   → ... (wszystkie wyniki dla wszystkich bib)

5. GET /api/event/89081/bracket?page=1&size=50
   → Pobiera: M30, K40, Overall, M, K, ...

6. GET /api/event/89081/results?results_bracket_name=M&page=1&size=100
   → Pobiera: bib=95, rank=?

7. GET /api/event/89081/results?results_bracket_name=M30&page=1&size=100
   → Pobiera: bib=95, rank=4

8. ... (dla każdego bracket)
```

### Auto-refresh co 10s (mode='live'):

```
1. GET /api/event/89081/results?page=1&size=100&interval=ALL
   → TYLKO WYNIKI (czasy, pozycje)
   → Country/city/club CACHED z bazy danych
```

---

## 7. PRZYKŁADOWE DANE - BIB 95 (Adrian Romański)

### Z /entry endpoint:
```json
{
  "entry_bib": "95",
  "athlete_first_name": "Adrian",
  "athlete_last_name": "Romański",
  "location_city": "Jędrzejów",
  "club": "ROMAX Running Team",
  "country_name": "Poland",
  "country": "PL",
  "location_country": "PL",
  "nationality": "Polish"
}
```

### Z /results endpoint:
```json
[
  {
    "results_bib": "95",
    "results_interval_name": "PK",
    "results_time": "00:08:07",
    "results_rank": 24
  },
  {
    "results_bib": "95",
    "results_interval_name": "PK2",
    "results_time": "00:16:39",
    "results_rank": 19
  },
  {
    "results_bib": "95",
    "results_interval_name": "Finish",
    "results_time": "00:25:04",
    "results_rank": 20,
    "results_country": "Poland",
    "results_hometown": "Jędrzejów, Poland"
  }
]
```

### W bazie danych (po process_single_result):
```
bib_number: 95
first_name: Adrian
last_name: Romański
city: Jędrzejów
club: ROMAX Running Team
country: Poland          ← NAZWA, nie kod!
nationality: Polish      ← lub "Poland"
position: 20
gender_position: ?
finish_time: 00:25:04
net_time: 00:24:57
split_times: [{"interval_name":"PK","time":"08:07",...},...]
```

### W JSON do frontend:
```json
{
  "bib_number": "95",
  "full_name": "Adrian Romański",
  "city": "Jędrzejów",
  "club": "ROMAX Running Team",
  "country": "Poland",
  "nationality": "Polish",
  "position": 20,
  "finish_time": "00:25:04"
}
```

### W JavaScript:
```javascript
countryCode = "Poland"  // ❌ powinno być "PL"
flagEmoji = CountryFlags.getFlag("Poland")  // → '' (puste)
```

---

## 8. PODSUMOWANIE - CO JEST NIE TAK?

### Problem główny:
System zapisuje **NAZWĘ kraju** ("Poland") zamiast **KODU kraju** ("PL").

### Przyczyna:
W `process_single_result()` (linia 1090):
```php
$country = $entry['country_name'] ?? $entry['country'] ?? ...;
// To daje "Poland" zamiast "PL"
```

### Rozwiązanie:
Trzeba ALBO:
1. Zapisywać kod kraju w osobnym polu `country_code`
2. Konwertować nazwę → kod przed zapisem
3. Dodać konwersję w JavaScript przed wywołaniem `CountryFlags.getFlag()`

---

## 9. GDZIE WPROWADZIĆ POPRAWKI

### Backend (PHP):

**Plik:** `includes/class-chronotrack-api.php`
**Linia:** ~1089-1135 (funkcja `process_single_result`)

**Zmiana:** Dodać pole `country_code`:
```php
// Zachować oryginalny kod kraju
$country_code = $entry['location_country'] ?? $result['results_country_code'] ?? '';
if (empty($country_code) && !empty($country)) {
    $country_code = $this->country_name_to_code($country);
}

return array(
    // ...
    'country' => $country,  // nazwa dla wyświetlenia
    'country_code' => $country_code,  // kod dla flag ⭐ NOWE
    'nationality' => $nationality,
);
```

### Frontend (JavaScript):

**Plik:** `assets/js/chronotrack-live.js`
**Linia:** ~510-515

**Zmiana:** Najpierw sprawdzać `country_code`:
```javascript
const countryCode = result.country_code || result.CountryCode || 
                    this.convertCountryNameToCode(result.country) ||
                    result.nationality;
```

---

**KONIEC DOKUMENTACJI**

Data utworzenia: 2026-04-03
Event: 89081 (Botaniczna Piątka)
