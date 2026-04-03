# Chronotrack API - Dokumentacja integracji

## 1. ENDPOINTY API

### 1.1. GET /api/event/{event_id}

**Pełny URL:**
```
https://api.chronotrack.com:443/api/event/89081?format=json&client_id=727dae7f&user_id=lukasz%40yogoevents.pl&user_pass=f2b2f3082a9d2ae5091eb5920bee538dc01bb413
```

**Pobierane atrybuty:**
- `event_id` => ID wydarzenia
- `event_name` => nazwa wydarzenia
- `event_date` => data wydarzenia (format: timestamp Unix)
- `event_end_time` => czas zakończenia wydarzenia (format: timestamp Unix) ⭐
- `event_location` => lokalizacja
- `event_city` => miasto
- `event_state` => województwo/stan
- `event_country` => kraj
- `event_timezone` => strefa czasowa

**Dodatkowa funkcjonalność:**
- Przy pierwszym pobraniu zapisz `event_end_time`
- O czasie `event_end_time` automatycznie wykonaj ostatnie pobranie danych
- Automatycznie zmień status z "na żywo" na "zakończone" (jeśli użytkownik nie zrobił tego wcześniej)

---

### 1.2. GET /api/event/{event_id}/entry

**Pełny URL:**
```
https://api.chronotrack.com:443/api/event/89081/entry?format=json&client_id=727dae7f&user_id=lukasz%40yogoevents.pl&user_pass=f2b2f3082a9d2ae5091eb5920bee538dc01bb413&page=1&size=50&include_test_entries=true&elide_json=false
```

**Pobierane atrybuty:**
- `bib` => numer startowy (klucz łączący z results) ⭐
- `first_name` => imię
- `last_name` => nazwisko
- `gender` => płeć (M/F)
- `age` => wiek
- `athlete_birthdate` => data urodzenia (format: RRRR-MM-DD) ⭐
  - Z tego pola wyciągaj **rok urodzenia** (RRRR)
- `city` => miasto
- `state` => województwo/stan
- `country` => kraj (ISO kod lub pełna nazwa) ⭐
- `nationality` => narodowość ⭐
- `team` => nazwa drużyny

**Atrybuty USUNIĘTE** (nie istnieją w API):
- ~~`country`~~ - nie ma w tym endpoincie
- ~~`nationality`~~ - nie ma w tym endpoincie
- ~~`club`~~ - nie ma w tym endpoincie
- ~~`athlete_club`~~ - nie ma w tym endpoincie
- ~~`race_distance`~~ - nie ma w tym endpoincie

---

### 1.3. GET /api/event/{event_id}/results

**Pełny URL:**
```
https://api.chronotrack.com:443/api/event/89081/results?format=json&client_id=727dae7f&user_id=lukasz%40yogoevents.pl&user_pass=f2b2f3082a9d2ae5091eb5920bee538dc01bb413&page=1&size=1000&interval=ALL
```

**Pobierane atrybuty:**
- `bib` => numer startowy (łączenie z entry) ⭐
- `results_time` => czas ukończenia (format: HH:MM:SS.sss z **SETNYMI SEKUND**) ⭐
  - Pobieram z setnych sekund (np. "00:17:06.350")
  - Zaokrąglam i formatuję do pełnych sekund (HH:MM:SS)
- `results_pace` => tempo (np. "05:42/km") ⭐
- `results_primary_bracket_name` => kategoria (np. "K20-29", "M40-49") ⭐
- `results_rank_overall` => pozycja generalna
- `results_rank_gender` => pozycja w płci
- `results_rank_primary` => pozycja w kategorii
- `results_status` => status (np. "Finished", "DNF", "DNS")

**Atrybuty ODPUSZCZONE** (są już w entry):
- ~~`results_first_name`~~ - mam w entry
- ~~`results_last_name`~~ - mam w entry
- ~~`results_hometown`~~ - mam w entry (city)
- ~~`results_country`~~ - mam w entry (country)
- ~~`results_nationality`~~ - mam w entry (nationality)

**Atrybuty NIEISTNIEJĄCE w API:**
- ~~`results_gender`~~ - nie ma w API
- ~~`results_category`~~ - nie ma (jest `results_primary_bracket_name`)

**Łączenie danych:**
- Zawodnicy łączeni po `bib` (entry + results)

---

### 1.4. GET /api/event/{event_id}/interval

**Pełny URL:**
```
https://api.chronotrack.com:443/api/event/89081/interval?format=json&client_id=727dae7f&user_id=lukasz%40yogoevents.pl&user_pass=f2b2f3082a9d2ae5091eb5920bee538dc01bb413&page=1&size=1000
```

**Pobierane atrybuty:**
- `event_id` => ID wydarzenia ⭐
- `race_name` => nazwa dystansu (np. "K1000", "Bieg Główny") ⭐
- `interval_name` => nazwa punktu (np. "PK2", "5KM", "Meta") ⭐
- `interval_iv_distance_m` => dystans punktu w metrach (np. "3300") ⭐
- `bib` => numer startowy
- `interval_time` => czas przejścia przez punkt (format: HH:MM:SS.sss)
  - Zaokrąglam do pełnych sekund (HH:MM:SS)

**Przykładowe dane:**
```json
{
  "event_id": "89081",
  "race_name": "Bieg Główny",
  "interval_name": "PK2",
  "interval_iv_distance_m": "3300",
  "bib": "123",
  "interval_time": "00:17:06.350"
}
```

**Atrybuty NIEISTNIEJĄCE:**
- ~~`interval_iv_name`~~ - nie ma alternatywnej nazwy

---

### 1.5. GET /api/event/{event_id}/bracket

**Pełny URL:**
```
https://api.chronotrack.com:443/api/event/89081/bracket?format=json&client_id=727dae7f&user_id=lukasz%40yogoevents.pl&user_pass=f2b2f3082a9d2ae5091eb5920bee538dc01bb413
```

**Pobierane atrybuty:**
- `bracket_id` => ID kategorii
- `bracket_name` => nazwa kategorii (np. "K20-29", "M40-49")
- `bracket_gender` => płeć kategorii (M/F/X)
- `bracket_min_age` => minimalny wiek
- `bracket_max_age` => maksymalny wiek

**Status:** ✅ OK - bez zmian

---

## 2. PRZETWARZANIE DANYCH

### 2.1. COUNTRY/NATIONALITY - Priorytety

**Pobierz dane tylko z dwóch pierwszych priorytetów:**

#### PRIORYTET 1: Entry API
```php
// Z endpointu /api/event/{event_id}/entry
$country = $entry['country'] ?? null;        // Może być ISO kod (PL) lub pełna nazwa (Poland)
$nationality = $entry['nationality'] ?? null; // Narodowość
```

#### PRIORYTET 2: Parsowanie hometown
```php
// Jeśli PRIORYTET 1 pusty, parsuj hometown/city z entry
// Format: "Września, Poland" lub "Warsaw, PL"
$hometown = $entry['city'] ?? null;

if ($hometown && strpos($hometown, ',') !== false) {
    $parts = explode(',', $hometown);
    $country = trim($parts[1]); // "Poland" lub "PL"
}
```

**Formatowanie:**
- Konwertuj kody ISO (PL, DE, US) → pełne nazwy (Poland, Germany, USA)
- Normalizuj nazwy krajów (Poland, Polska → Poland)
- Zapisz w bazie w ustandaryzowanej formie

---

### 2.2. FORMATOWANIE CZASU

**Zasada:** Czas **ZAWSZE** formatować do pełnych sekund

```php
// Wejście z API: "00:17:06.350" (z setnych sekund)
// Wyjście do bazy: "00:17:06" (pełne sekundy)

function formatTime($time_with_ms) {
    // Usuń setne sekundy
    $time = explode('.', $time_with_ms)[0];
    return $time; // "00:17:06"
}
```

**Dotyczy:**
- `results_time` z `/api/event/{event_id}/results`
- `interval_time` z `/api/event/{event_id}/interval`

---

### 2.3. ROK URODZENIA

**Źródło:** `athlete_birthdate` z `/api/event/{event_id}/entry`

```php
// Format: "RRRR-MM-DD" (np. "1995-03-15")
$birthdate = $entry['athlete_birthdate'] ?? null;

if ($birthdate) {
    $birth_year = substr($birthdate, 0, 4); // "1995"
    // Zapisz rok urodzenia w bazie
}
```

---

### 2.4. TEMPO (PACE)

**Źródło:** `results_pace` z `/api/event/{event_id}/results`

```php
// Format: "05:42/km" lub "05:42"
$pace = $result['results_pace'] ?? null;

// Zapisz bezpośrednio do bazy
// Nie gubić tego pola!
```

---

## 3. LOGIKA AUTOMATYZACJI

### 3.1. Automatyczne zakończenie wydarzenia

```php
// 1. Przy pierwszym pobraniu danych z /api/event/{event_id}
$event_end_time = $event['event_end_time']; // timestamp Unix

// 2. Ustaw zadanie cron/scheduler na ten czas
// 3. O czasie event_end_time:
//    - Pobierz ostatni raz wszystkie dane (entry, results, interval)
//    - Jeśli status == "na żywo", zmień na "zakończone"

if (time() >= $event_end_time && $event_status == 'live') {
    // Ostatnie pobranie danych
    fetchAllData($event_id);
    
    // Zmiana statusu
    updateEventStatus($event_id, 'finished');
}
```

---

## 4. MAPOWANIE ATRYBUTÓW

### Tabela: events
| Pole bazy | Źródło API | Endpoint |
|-----------|------------|----------|
| event_id | event_id | /api/event/{id} |
| name | event_name | /api/event/{id} |
| date | event_date | /api/event/{id} |
| end_time | event_end_time | /api/event/{id} ⭐ |
| location | event_location | /api/event/{id} |
| city | event_city | /api/event/{id} |
| country | event_country | /api/event/{id} |
| timezone | event_timezone | /api/event/{id} |
| status | - | Automatycznie na podstawie end_time |

### Tabela: athletes
| Pole bazy | Źródło API | Endpoint |
|-----------|------------|----------|
| bib | bib | /api/event/{id}/entry |
| first_name | first_name | /api/event/{id}/entry |
| last_name | last_name | /api/event/{id}/entry |
| gender | gender | /api/event/{id}/entry |
| age | age | /api/event/{id}/entry |
| birth_year | athlete_birthdate (RRRR) | /api/event/{id}/entry ⭐ |
| city | city | /api/event/{id}/entry |
| country | country (priorytet 1-2) | /api/event/{id}/entry |
| nationality | nationality (priorytet 1-2) | /api/event/{id}/entry |
| team | team | /api/event/{id}/entry |

### Tabela: results
| Pole bazy | Źródło API | Endpoint |
|-----------|------------|----------|
| bib | bib | /api/event/{id}/results |
| time | results_time (zaokr.) | /api/event/{id}/results ⭐ |
| pace | results_pace | /api/event/{id}/results ⭐ |
| category | results_primary_bracket_name | /api/event/{id}/results ⭐ |
| rank_overall | results_rank_overall | /api/event/{id}/results |
| rank_gender | results_rank_gender | /api/event/{id}/results |
| rank_category | results_rank_primary | /api/event/{id}/results |
| status | results_status | /api/event/{id}/results |

### Tabela: intervals
| Pole bazy | Źródło API | Endpoint |
|-----------|------------|----------|
| event_id | event_id | /api/event/{id}/interval ⭐ |
| race_name | race_name | /api/event/{id}/interval ⭐ |
| interval_name | interval_name | /api/event/{id}/interval ⭐ |
| distance_m | interval_iv_distance_m | /api/event/{id}/interval ⭐ |
| bib | bib | /api/event/{id}/interval |
| time | interval_time (zaokr.) | /api/event/{id}/interval ⭐ |

---

## 5. KLUCZOWE ZMIANY

✅ **Dodano:**
- `event_end_time` - automatyczne zakończenie
- `race_name` - przypisanie dystansu do punktu
- `results_pace` - tempo (nie gubić!)
- `birth_year` - rok urodzenia z athlete_birthdate
- Formatowanie czasu do pełnych sekund

❌ **Usunięto nieistniejące atrybuty:**
- ~~country/nationality/club~~ z entry
- ~~results_first_name/last_name~~ (dublowane)
- ~~results_hometown/country/nationality~~ (dublowane)
- ~~results_gender/results_category~~ (nie istnieją)
- ~~interval_iv_name~~ (nie istnieje)

🎯 **Priorytety country/nationality:**
- Tylko 1 i 2 (entry → parsowanie hometown)

---

## 6. PRZYKŁADOWY PRZEPŁYW DANYCH

```php
// 1. Pobierz event
$event = fetchEvent(89081);
$end_time = $event['event_end_time']; // 1760792400

// 2. Pobierz zawodników
$entries = fetchEntries(89081);
foreach ($entries as $entry) {
    $bib = $entry['bib'];
    $country = $entry['country'] ?? parseHometown($entry['city']);
    $birth_year = substr($entry['athlete_birthdate'], 0, 4);
}

// 3. Pobierz wyniki
$results = fetchResults(89081);
foreach ($results as $result) {
    $bib = $result['bib'];
    $time = formatTime($result['results_time']); // "00:17:06.350" → "00:17:06"
    $pace = $result['results_pace']; // "05:42/km"
    $category = $result['results_primary_bracket_name']; // "K20-29"
}

// 4. Pobierz punkty pośrednie
$intervals = fetchIntervals(89081);
foreach ($intervals as $interval) {
    $race = $interval['race_name']; // "Bieg Główny"
    $point = $interval['interval_name']; // "PK2"
    $distance = $interval['interval_iv_distance_m']; // "3300"
    $time = formatTime($interval['interval_time']); // Zaokrąglone
}

// 5. O czasie end_time - automatyczne zakończenie
if (time() >= $end_time) {
    finalFetch(89081);
    updateStatus(89081, 'finished');
}
```

---

**Wersja:** 1.0  
**Data aktualizacji:** 2026-04-03  
**Status:** ✅ Zweryfikowano z rzeczywistym API
