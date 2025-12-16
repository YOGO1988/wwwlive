# ChronoTrack API Reference

Complete documentation of ChronoTrack API endpoints used in this plugin.

## Base Configuration

```
Base URL: https://api.chronotrack.com
Client ID: 727dae7f
User ID: lukasz@yogoevents.pl
User Pass: [API password]
```

All endpoints require authentication parameters:
- `client_id` - Client identifier
- `user_id` - User email
- `user_pass` - API password

---

## 📊 Core Endpoints

### 1. Event Information

**Endpoint:** `/api/event/{event_id}`

**Parameters:**
- `client_id` (required)
- `user_id` (required)
- `user_pass` (required)

**Example:**
```
https://api.chronotrack.com/api/event/89332?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS
```

**Returns:**
```json
{
  "event": {
    "event_id": "89332",
    "event_name": "Botaniczna Piątka",
    "event_start_time": "1761469200",
    "location_city": "Warsaw",
    "location_country": "Poland",
    "event_is_published": "1"
  }
}
```

**Fields:**
- `event_name` - Event name
- `event_start_time` - Unix timestamp
- `location_city` - City where event takes place
- `event_is_published` - Publication status

---

### 2. Participant Entries

**Endpoint:** `/api/event/{event_id}/entry`

**Parameters:**
- `page` - Page number (default: 1)
- `size` - Results per page (max: 100)
- `include_test_entries` - Include test entries (true/false)
- `elide_json` - Compact JSON (true/false)
- `contact_details` - Include contact info (true/false)
- `include_all_fields` - Include all available fields (true/false)
- `need_athlete_birthdate` - Include birthdate (true/false)

**Example:**
```
https://api.chronotrack.com/api/event/89332/entry?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS&page=1&size=50&include_all_fields=true&need_athlete_birthdate=true
```

**Returns:**
```json
{
  "event_entry": [
    {
      "entry_bib": "147",
      "entry_name": "SEBASTIAN LEWANDOWSKI",
      "athlete_first_name": "Sebastian",
      "athlete_last_name": "Lewandowski",
      "athlete_sex": "M",
      "athlete_birthdate": "1988-07-04",
      "bracket_name": "M30",
      "wave_name": "Zielona",
      "location_city": "Warsaw",
      "custom_element262177": "#ostatniNaMecie",
      "race_name": "Bieg Główny"
    }
  ]
}
```

**Important Fields:**
- `entry_bib` - Bib number (unique identifier)
- `athlete_birthdate` - Format: YYYY-MM-DD
- `location_city` - Athlete's city
- `custom_element*` - Custom fields (club often stored here)
- `race_name` - Distance/race name
- `wave_name` - Start wave name

---

### 3. Results - OPEN (Overall Positions)

**Endpoint:** `/api/event/{event_id}/results`

**Parameters:**
- `format` - Response format (json)
- `page` - Page number
- `per_page` or `size` - Results per page
- `include_all_fields` - Include all fields (true)
- `need_athlete_birthdate` - Include birthdate (true)
- `need_transaction_account` - Include transaction data (true)
- `interval` - Interval filter (ALL for all timing points)

**Example:**
```
https://api.chronotrack.com/api/event/89332/results?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS&format=json&page=1&per_page=100&include_all_fields=true&interval=ALL
```

**Returns:**
```json
{
  "event_results": [
    {
      "results_bib": "147",
      "results_first_name": "Marcin",
      "results_last_name": "Galant",
      "results_sex": "M",
      "results_age": "37",
      "results_primary_bracket_name": "M",
      "results_event_name": "Botaniczna Piątka",
      "results_race_name": "Bieg Główny",
      "results_interval_name": "Full Course",
      "results_rank": "1",
      "results_time": "00:17:18",
      "results_gun_time": "00:17:20",
      "results_pace": "00:03:27",
      "results_pace_unit": "min/km",
      "results_hometown": "Warsaw"
    }
  ],
  "page": 1,
  "page_count": 5
}
```

**Critical Fields:**
- `results_rank` - **OVERALL POSITION** (1, 2, 3...)
- `results_interval_name` - "Full Course" = finish, other = split point
- `results_time` - Net time (chip time)
- `results_gun_time` - Gun time
- `results_pace` - Pace per kilometer/mile

**⚠️ IMPORTANT:** This endpoint does NOT return `results_sex_rank` or `results_division_rank`!

---

### 4. Results - SEX Bracket (Gender Positions)

**Endpoint:** `/api/event/{event_id}/results?bracket=SEX`

**Parameters:**
- `bracket` - **Must be "SEX"**
- `format` - json
- `page`, `size` - Pagination

**Example:**
```
https://api.chronotrack.com/api/event/89332/results?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS&format=json&bracket=SEX&page=1&size=100
```

**Returns:**
```json
{
  "event_results": [
    {
      "results_bib": "181",
      "results_sex": "F",
      "results_primary_bracket_name": "K40",
      "results_bracket_name": "K",
      "results_rank": "5",
      "results_time": "00:23:45"
    }
  ]
}
```

**⚠️ KEY INSIGHT:**
- `bracket=SEX` returns **BOTH** men and women in one request
- `results_rank` in this context = **POSITION WITHIN GENDER** (M or F)
- `results_sex` = "M" or "F"
- `results_bracket_name` = "K" (women) or "M" (men)

---

### 5. Results - AGE Bracket (Category Positions)

**Endpoint:** `/api/event/{event_id}/results?bracket=AGE`

**Parameters:**
- `bracket` - **Must be "AGE"**
- `format` - json
- `page`, `size` - Pagination

**Example:**
```
https://api.chronotrack.com/api/event/89332/results?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS&format=json&bracket=AGE&page=1&size=100
```

**Returns:**
```json
{
  "event_results": [
    {
      "results_bib": "147",
      "results_primary_bracket_name": "M30",
      "results_bracket_name": "M30",
      "results_rank": "2",
      "results_hometown": "Warsaw"
    }
  ]
}
```

**⚠️ KEY INSIGHT:**
- `results_rank` in this context = **POSITION WITHIN AGE CATEGORY** (M30, K40, etc.)
- `results_primary_bracket_name` = Category name (M30, K40, M50, etc.)

---

### 6. Results - Custom Bracket

**Endpoint:** `/api/event/{event_id}/results?bracket={CATEGORY_NAME}`

**Example (Police category):**
```
https://api.chronotrack.com/api/event/87968/results?bracket=Policja
```

**Example (Fire department category):**
```
https://api.chronotrack.com/api/event/87968/results?bracket=OSP
```

**Usage:**
- Replace `{CATEGORY_NAME}` with any custom bracket name
- `results_rank` = position within that custom category

---

### 7. Results - Interval (Timing Points)

**Endpoint:** `/api/event/{event_id}/results?interval={TIMING_POINT_NAME}`

**Example:**
```
https://api.chronotrack.com/api/event/89081/results?interval=PK
```

**Returns:**
- `results_time` - Time at this timing point
- `results_rank` - Position at this timing point

**Combined with Bracket:**
```
https://api.chronotrack.com/api/event/89081/results?interval=PK&bracket=SEX
```
Returns position at timing point within gender category.

---

### 8. Brackets List

**Endpoint:** `/api/event/{event_id}/bracket`

**Example:**
```
https://api.chronotrack.com/api/event/89332/bracket?format=json&page=1&size=50
```

**Returns:**
```json
{
  "event_brackets": [
    {
      "bracket_name": "M30",
      "bracket_type": "age"
    },
    {
      "bracket_name": "K40",
      "bracket_type": "age"
    },
    {
      "bracket_name": "Policja",
      "bracket_type": "custom"
    }
  ]
}
```

**Usage:**
- Get list of all available categories in the event
- Use bracket names for custom queries

---

### 9. Courses (Timing Points)

**Endpoint:** `/api/event/{event_id}/course`

**Example:**
```
https://api.chronotrack.com/api/event/89332/course?format=json
```

**Returns:**
```json
{
  "courses": [
    {
      "race_name": "Bieg Główny",
      "race_course_distance": "5000",
      "timing_points": [
        {
          "timing_point_name": "PK 2.5km",
          "timing_point_cp_type": "internal",
          "timing_point_distance_from_start": "2500"
        }
      ]
    }
  ]
}
```

**Fields:**
- `race_course_distance` - Total distance in meters
- `timing_point_cp_type` - "internal" = intermediate, "finish" = finish line
- `timing_point_distance_from_start` - Distance in meters from start

---

### 10. Races (Distances)

**Endpoint:** `/api/event/{event_id}/race`

**Example:**
```
https://api.chronotrack.com/api/event/89332/race?format=json&page=1&size=50&include_not_wants_results=true
```

**Returns:**
```json
{
  "races": [
    {
      "race_name": "Bieg Główny",
      "race_course_distance": "5000"
    },
    {
      "race_name": "Maraton",
      "race_course_distance": "42195"
    }
  ]
}
```

**Usage:**
- Get list of all distances/races in event
- `race_course_distance` in meters

---

### 11. Registration Choices

**Endpoint:** `/api/event/{event_id}/reg-choice`

**Example:**
```
https://api.chronotrack.com/api/event/89332/reg-choice?page=1&per_page=100
```

**Returns:**
```json
{
  "reg_choices": [
    {
      "reg_choice_id": "12345",
      "reg_choice_name": "Bieg Główny",
      "event_name": "Botaniczna Piątka"
    }
  ]
}
```

**Fields:**
- `reg_choice_id` - Distance ID (use for filtering)
- `reg_choice_name` - Distance name
- Note: `reg_choice_distance` field may not exist

---

### 12. Waves

**Endpoint:** `/api/event/{event_id}/wave`

**Example:**
```
https://api.chronotrack.com/api/event/89081/wave?format=json&page=1&size=50
```

**Returns:**
```json
{
  "waves": [
    {
      "wave_name": "Zielona",
      "wave_start_time": "1761469200",
      "race_name": "Bieg Główny"
    }
  ]
}
```

**Usage:**
- Get information about start waves
- Each distance can have multiple waves

---

## 🔑 Implementation Strategy

### How to Get Complete Results

The plugin uses a **3-endpoint merge strategy**:

1. **Fetch OPEN results** (`/results` without bracket)
   - Get all athlete data, times, splits
   - Get `results_rank` = overall position
   - Store by BIB number

2. **Fetch SEX bracket** (`/results?bracket=SEX`)
   - Get `results_rank` for each BIB
   - This is the **gender position**
   - Store by BIB number

3. **Fetch AGE bracket** (`/results?bracket=AGE`)
   - Get `results_rank` for each BIB
   - This is the **category position**
   - Get `results_primary_bracket_name` = category
   - Store by BIB number

4. **Merge by BIB**
   - Combine all data for each athlete
   - Final result has:
     - Overall position (from OPEN)
     - Gender position (from SEX)
     - Category position (from AGE)
     - Category name (from AGE)
     - All times and athlete data (from OPEN)

### Pagination

**CRITICAL:** All endpoints require pagination!

```php
$page = 1;
$has_more_pages = true;

while ($has_more_pages && $page <= 50) {
    $response = fetch_page($page);

    // Check if more pages exist
    if (isset($response['page_count'])) {
        $has_more_pages = $page < $response['page_count'];
    } elseif (count($response['event_results']) >= 100) {
        $has_more_pages = true;
    } else {
        $has_more_pages = false;
    }

    $page++;
}
```

**Safety:** Set max page limit (e.g., 50 pages = 5000 results)

---

## 📝 Field Mapping Reference

| Display Field | OPEN Endpoint | SEX Bracket | AGE Bracket |
|--------------|---------------|-------------|-------------|
| BIB Number | results_bib | results_bib | results_bib |
| Name | results_first_name, results_last_name | - | - |
| Gender | results_sex | results_sex | - |
| Age | results_age | - | - |
| Category | results_primary_bracket_name | - | results_primary_bracket_name |
| **Overall Position** | **results_rank** | - | - |
| **Gender Position** | - | **results_rank** | - |
| **Category Position** | - | - | **results_rank** |
| Net Time | results_time | - | - |
| Gun Time | results_gun_time | - | - |
| Pace | results_pace | - | - |
| Distance | results_race_name | - | - |
| City | - | - | results_hometown |

---

## 🚨 Common Pitfalls

1. **DON'T expect gender/category positions in OPEN results**
   - Fields like `results_sex_rank`, `results_division_rank` are NOT returned
   - You MUST use bracket endpoints

2. **DON'T forget pagination**
   - Default limit is 50-100 results
   - Large events need multiple pages

3. **DON'T assume bracket=SEX returns only one gender**
   - It returns M and F together
   - Filter by `results_sex` field

4. **DON'T mix up interval vs finish results**
   - Check `results_interval_name`
   - "Full Course" or "Finish" = final result
   - Other names = intermediate timing points

5. **Date format**
   - `event_start_time` is Unix timestamp
   - `athlete_birthdate` is YYYY-MM-DD string

---

## 📊 Example: Complete Workflow

```php
// 1. Get event info
$event = fetch("/api/event/89332");

// 2. Get participant entries (city, club, custom fields)
$entries = fetch_all_pages("/api/event/89332/entry");

// 3. Get OPEN results (times, overall positions)
$open_results = fetch_all_pages("/api/event/89332/results?interval=ALL");

// 4. Get gender positions
$sex_results = fetch_all_pages("/api/event/89332/results?bracket=SEX");

// 5. Get category positions
$age_results = fetch_all_pages("/api/event/89332/results?bracket=AGE");

// 6. Merge by BIB
foreach ($open_results as $result) {
    $bib = $result['results_bib'];

    $merged[$bib] = [
        'name' => $result['results_first_name'] . ' ' . $result['results_last_name'],
        'overall_position' => $result['results_rank'],
        'gender_position' => $sex_results[$bib]['results_rank'] ?? 0,
        'category_position' => $age_results[$bib]['results_rank'] ?? 0,
        'category' => $age_results[$bib]['results_primary_bracket_name'] ?? '',
        'time' => $result['results_time'],
        'city' => $entries[$bib]['location_city'] ?? ''
    ];
}
```

---

## 🔗 Quick Reference Links

Replace `{EVENT_ID}` and `YOUR_PASS` with actual values:

```
Event Info:
https://api.chronotrack.com/api/event/{EVENT_ID}?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS

OPEN Results:
https://api.chronotrack.com/api/event/{EVENT_ID}/results?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS&format=json&page=1&per_page=100&include_all_fields=true&interval=ALL

SEX Bracket:
https://api.chronotrack.com/api/event/{EVENT_ID}/results?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS&format=json&bracket=SEX&page=1&size=100

AGE Bracket:
https://api.chronotrack.com/api/event/{EVENT_ID}/results?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS&format=json&bracket=AGE&page=1&size=100

Brackets List:
https://api.chronotrack.com/api/event/{EVENT_ID}/bracket?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS&format=json

Races/Distances:
https://api.chronotrack.com/api/event/{EVENT_ID}/race?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS&format=json

Courses (Timing Points):
https://api.chronotrack.com/api/event/{EVENT_ID}/course?client_id=727dae7f&user_id=lukasz@yogoevents.pl&user_pass=YOUR_PASS&format=json
```

---

## 📄 Version History

- **v4.0.0** - Implemented 3-endpoint merge strategy for complete position data
- **v3.9.0** - Added debug logging (deprecated)
- **v3.8.x** - Single endpoint approach (incomplete - missing positions)

---

**Author:** YOGO Events
**Last Updated:** 2025-01-22
**Plugin Version:** 4.0.0
