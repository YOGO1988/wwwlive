# ChronoTrack Live Results

WordPress plugin for displaying live race results from ChronoTrack with multi-event support and detailed participant statistics.

## Version 3.6.0

### Features

- ✅ **Multi-Event Support** - Manage multiple events with persistent results
- ✅ **Automatic Page Generation** - No shortcodes needed, pages created automatically
- ✅ **Live Results** - Auto-refreshing standings view
- ✅ **Finish Line (META) View** - Shows latest finishers in reverse chronological order
- ✅ **Detailed Participant Stats** - Click any runner to see comprehensive details
- ✅ **Split Times** - Configurable checkpoints with position tracking
- ✅ **Logo Size Restrictions** - Configurable maximum sizes for event/sponsor logos
- ✅ **Search & Filtering** - Filter by name, bib, category, gender
- ✅ **Responsive Design** - Works on all devices

### Installation

1. Download the plugin as a ZIP file
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Activate the plugin

### Usage

#### Creating an Event

1. Go to **ChronoTrack Live > Add New Event**
2. Fill in the event details:
   - **Event ID** - Your ChronoTrack event ID
   - **Event Name** - Display name
   - **Event Date** - Date and time of the event
   - **Event Status** - Active (auto-refresh), Completed (archived), or Upcoming
   - **Event Logo** - Optional logo (will be resized to max configured size)
   - **Sponsor Logo** - Optional sponsor logo
   - **Split Times** - Configure checkpoints (e.g., T1, 10km, T2, etc.)

3. Click **Create Event**

The plugin will automatically:
- Create a page for the event
- Set up auto-refresh for active events
- Display results in both Standings and Finish Line (META) views

#### Viewing Results

Navigate to the event page. You'll see:

**Standings View** - Traditional race results ordered by position (1st, 2nd, 3rd...)

**Finish Line (META) View** - Latest finishers shown first, updating in real-time

**Participant Details** - Click any name to see:
- Basic information (bib, age, gender, city, club, category)
- Results (overall, category, and gender positions)
- Split times with position changes
- Performance analysis (passed others ↑, was passed ↓, position unchanged =)

#### Settings

Go to **ChronoTrack Live > Settings** to configure:

- **Refresh Interval** - How often to update results (default: 5000ms = 5 seconds)
- **Maximum Logo Width** - Max width for logos (default: 400px)
- **Maximum Logo Height** - Max height for logos (default: 200px)

### Split Times Configuration

Split times are perfect for multi-segment races like triathlons:

**Example - Triathlon:**
- Start (show in main: no)
- T1 (show in main: yes)
- Bike Start (show in main: no)
- Bike 10km (show in main: no)
- T2 (show in main: yes)
- Run 5km (show in main: no)
- Finish (show in main: yes)

Checkpoints marked "Show in main results" will appear in the main results table.
All checkpoints appear in the detailed participant view.

### Managing Multiple Events

The plugin supports unlimited events:

- **Active Events** - Auto-refresh results, shown first
- **Completed Events** - Archived with static results available
- **Upcoming Events** - Pre-configured, ready to activate

When an event ends:
1. Change status to "Completed"
2. Results remain accessible on the event page
3. Auto-refresh stops to reduce server load
4. Create a new event for your next race

### Troubleshooting

**Results not loading?**
- Check that Event ID is correct
- Verify ChronoTrack API is accessible
- Check browser console for JavaScript errors

**Logos too large?**
- Adjust max logo size in Settings
- Logos are automatically constrained to configured dimensions

**Need help?**
- Check browser console (F12) for debug messages
- Plugin logs detailed initialization info

### Developer Info

**Database Tables:**
- `wp_chronotrack_events` - Event configurations
- `wp_chronotrack_results` - Cached results
- `wp_chronotrack_splits` - Split time details

**AJAX Endpoints:**
- `chronotrack_get_results` - Fetch standings
- `chronotrack_get_recent_finishers` - Fetch META view
- `chronotrack_get_participant_details` - Fetch participant details

**Filters & Hooks:**
Available for customization (contact developer for details)

### Changelog

#### 3.6.0
- Added Finish Line (META) view
- Implemented detailed participant statistics
- Added configurable split times
- Logo size restrictions
- Multi-event support with persistent results
- Automatic page generation
- Position change tracking in splits
- Search and filtering improvements

#### 3.5.0
- Previous version (baseline)

### Credits

Developed by YOGO Events
https://yogoevents.pl

### License

GPL v2 or later
