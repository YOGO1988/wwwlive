<?php
/**
 * Participant details template
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="chronotrack-participant-details">
    <h2><?php echo esc_html($result->first_name . ' ' . $result->last_name); ?></h2>

    <div class="chronotrack-details-grid">

        <!-- Basic Information -->
        <div class="chronotrack-details-section">
            <h3><?php _e('Basic Information', 'chronotrack-live'); ?></h3>
            <table class="chronotrack-details-table">
                <tr>
                    <th><?php _e('Bib Number:', 'chronotrack-live'); ?></th>
                    <td><?php echo esc_html($result->bib_number); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Age:', 'chronotrack-live'); ?></th>
                    <td><?php echo esc_html($result->age); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Gender:', 'chronotrack-live'); ?></th>
                    <td><?php echo esc_html($result->gender); ?></td>
                </tr>
                <tr>
                    <th><?php _e('City:', 'chronotrack-live'); ?></th>
                    <td><?php echo esc_html($result->city); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Club:', 'chronotrack-live'); ?></th>
                    <td><?php echo esc_html($result->club); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Category:', 'chronotrack-live'); ?></th>
                    <td><?php echo esc_html($result->category); ?></td>
                </tr>
            </table>
        </div>

        <!-- Results -->
        <div class="chronotrack-details-section">
            <h3><?php _e('Results', 'chronotrack-live'); ?></h3>
            <table class="chronotrack-details-table">
                <tr>
                    <th><?php _e('Overall Position:', 'chronotrack-live'); ?></th>
                    <td class="chronotrack-position"><?php echo esc_html($result->position); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Category Position:', 'chronotrack-live'); ?></th>
                    <td class="chronotrack-position"><?php echo esc_html($result->category_position); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Gender Position:', 'chronotrack-live'); ?></th>
                    <td class="chronotrack-position"><?php echo esc_html($result->gender_position); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Finish Time (Gross):', 'chronotrack-live'); ?></th>
                    <td class="chronotrack-time"><?php echo esc_html($result->finish_time); ?></td>
                </tr>
                <tr>
                    <th><?php _e('Net Time:', 'chronotrack-live'); ?></th>
                    <td class="chronotrack-time"><?php echo esc_html($result->net_time); ?></td>
                </tr>
            </table>
        </div>

        <!-- Split Times -->
        <?php if (!empty($result->detailed_splits) && count($result->detailed_splits) > 0): ?>
        <div class="chronotrack-details-section chronotrack-splits-section">
            <h3><?php _e('Split Times', 'chronotrack-live'); ?></h3>
            <table class="chronotrack-splits-table">
                <thead>
                    <tr>
                        <th><?php _e('Checkpoint', 'chronotrack-live'); ?></th>
                        <th><?php _e('Time', 'chronotrack-live'); ?></th>
                        <th><?php _e('Position', 'chronotrack-live'); ?></th>
                        <th><?php _e('Segment', 'chronotrack-live'); ?></th>
                        <th><?php _e('Pace', 'chronotrack-live'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $previous_position = 0;
                    $previous_pace_seconds = 0;

                    // Helper function to parse pace to seconds
                    $parse_pace_to_seconds = function($pace_str) {
                        if (empty($pace_str) || $pace_str === '-') return 0;
                        $parts = explode(':', $pace_str);
                        if (count($parts) === 2) {
                            return intval($parts[0]) * 60 + intval($parts[1]);
                        }
                        return 0;
                    };

                    foreach ($result->detailed_splits as $split):
                        $position_change = '';
                        $position_class = '';

                        // Position trend
                        if ($previous_position > 0) {
                            if ($split->checkpoint_position < $previous_position) {
                                $position_change = '▲';
                                $position_class = 'trend-up';
                            } elseif ($split->checkpoint_position > $previous_position) {
                                $position_change = '▼';
                                $position_class = 'trend-down';
                            } else {
                                $position_change = '-';
                                $position_class = 'trend-same';
                            }
                        }
                        $previous_position = $split->checkpoint_position;

                        // Pace trend
                        $pace_change = '';
                        $pace_class = '';
                        $current_pace = $split->segment_pace ?? '-';
                        if ($current_pace !== '-') {
                            $current_pace_seconds = $parse_pace_to_seconds($current_pace);
                            if ($previous_pace_seconds > 0 && $current_pace_seconds > 0) {
                                if ($current_pace_seconds < $previous_pace_seconds) {
                                    $pace_change = '▲'; // Faster
                                    $pace_class = 'trend-up';
                                } elseif ($current_pace_seconds > $previous_pace_seconds) {
                                    $pace_change = '▼'; // Slower
                                    $pace_class = 'trend-down';
                                } else {
                                    $pace_change = '-';
                                    $pace_class = 'trend-same';
                                }
                            }
                            $previous_pace_seconds = $current_pace_seconds;
                        }
                    ?>
                    <tr>
                        <td class="checkpoint-name"><?php echo esc_html($split->checkpoint_name); ?></td>
                        <td class="checkpoint-time"><?php echo esc_html($split->checkpoint_time); ?></td>
                        <td class="checkpoint-position">
                            <?php echo esc_html($split->checkpoint_position); ?>
                            <?php if ($position_change): ?>
                                <span class="<?php echo $position_class; ?>">
                                    <?php echo esc_html($position_change); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="segment-time"><?php echo esc_html($split->segment_time); ?></td>
                        <td class="segment-pace">
                            <?php echo esc_html($current_pace); ?>
                            <?php if ($pace_change): ?>
                                <span class="<?php echo $pace_class; ?>">
                                    <?php echo esc_html($pace_change); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="chronotrack-position-legend">
                <p>
                    <span class="trend-up">▲</span> <?php _e('Better (higher position or faster pace)', 'chronotrack-live'); ?> &nbsp;
                    <span class="trend-down">▼</span> <?php _e('Worse (lower position or slower pace)', 'chronotrack-live'); ?> &nbsp;
                    <span class="trend-same">-</span> <?php _e('No change', 'chronotrack-live'); ?>
                </p>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
