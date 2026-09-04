<?php
/**
 * Logs screen.
 *
 * @var array<string, mixed>   $filters       Active filters.
 * @var array<string, mixed>   $results       Query result with items, total, pages, page.
 * @var array<int, string>     $mapping_names Mapping id => repository name.
 * @var string                 $clear_url     Nonced URL that empties the log.
 */

defined('ABSPATH') || exit;

$level_labels = [
    'info'    => __('Info', 'github-sync'),
    'warning' => __('Warning', 'github-sync'),
    'error'   => __('Error', 'github-sync'),
];
?>
<div class="wrap github-sync">
    <div class="github-sync-page-head">
        <div>
            <h1><?php esc_html_e('Activity log', 'github-sync'); ?></h1>
            <p class="github-sync-page-head__sub">
                <?php esc_html_e('Every pull and push writes here, including what it skipped and why it failed.', 'github-sync'); ?>
            </p>
        </div>

        <?php if ($results['total'] > 0) : ?>
            <a href="<?php echo esc_url($clear_url); ?>" class="button"
                onclick="return confirm('<?php echo esc_js(__('Delete every log entry?', 'github-sync')); ?>');">
                <?php esc_html_e('Clear log', 'github-sync'); ?>
            </a>
        <?php endif; ?>
    </div>

    <?php settings_errors('github_sync_logs'); ?>

    <form method="get" class="github-sync-log-filters">
        <input type="hidden" name="page" value="github-sync-logs">

        <label class="screen-reader-text" for="github-sync-level"><?php esc_html_e('Level', 'github-sync'); ?></label>
        <select name="level" id="github-sync-level">
            <option value=""><?php esc_html_e('All levels', 'github-sync'); ?></option>
            <?php foreach ($level_labels as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['level'], $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="screen-reader-text" for="github-sync-mapping"><?php esc_html_e('Mapping', 'github-sync'); ?></label>
        <select name="mapping_id" id="github-sync-mapping">
            <option value="0"><?php esc_html_e('All mappings', 'github-sync'); ?></option>
            <?php foreach ($mapping_names as $mapping_id => $name) : ?>
                <option value="<?php echo esc_attr((string) $mapping_id); ?>" <?php selected((int) $filters['mapping_id'], (int) $mapping_id); ?>>
                    <?php echo esc_html($name); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="screen-reader-text" for="github-sync-search"><?php esc_html_e('Search', 'github-sync'); ?></label>
        <input type="search" name="s" id="github-sync-search" value="<?php echo esc_attr((string) $filters['search']); ?>"
            placeholder="<?php esc_attr_e('Search messages', 'github-sync'); ?>">

        <?php submit_button(__('Filter', 'github-sync'), 'secondary', '', false); ?>
    </form>

    <table class="wp-list-table widefat fixed striped github-sync-logs">
        <thead>
            <tr>
                <th scope="col" class="column-date"><?php esc_html_e('When', 'github-sync'); ?></th>
                <th scope="col" class="column-level"><?php esc_html_e('Level', 'github-sync'); ?></th>
                <th scope="col" class="column-mapping"><?php esc_html_e('Mapping', 'github-sync'); ?></th>
                <th scope="col" class="column-message"><?php esc_html_e('Message', 'github-sync'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($results['items'])) : ?>
                <tr>
                    <td colspan="4"><?php esc_html_e('No log entries match these filters.', 'github-sync'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ($results['items'] as $entry) : ?>
                    <tr>
                        <td class="column-date">
                            <?php echo esc_html(\GithubSync\Support\Dates::format($entry->created_at)); ?>
                        </td>
                        <td class="column-level">
                            <span class="github-sync-level github-sync-level--<?php echo esc_attr($entry->level); ?>">
                                <?php echo esc_html($level_labels[$entry->level] ?? $entry->level); ?>
                            </span>
                        </td>
                        <td class="column-mapping">
                            <?php echo esc_html($entry->mapping_id && isset($mapping_names[$entry->mapping_id]) ? $mapping_names[$entry->mapping_id] : '—'); ?>
                        </td>
                        <td class="column-message">
                            <?php echo esc_html($entry->message); ?>
                            <?php
                            $context_rows = empty($entry->context) ? [] : \GithubSync\Admin\Logs::context_rows($entry->context);
                            ?>
                            <?php if ($context_rows) : ?>
                                <details class="github-sync-log-context">
                                    <summary><?php esc_html_e('Details', 'github-sync'); ?></summary>
                                    <dl>
                                        <?php foreach ($context_rows as $row) : ?>
                                            <dt><?php echo esc_html($row['label']); ?></dt>
                                            <dd>
                                                <?php if ($row['url'] !== '') : ?>
                                                    <a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener noreferrer">
                                                        <?php echo esc_html($row['url']); ?>
                                                    </a>
                                                <?php else : ?>
                                                    <?php echo nl2br(esc_html($row['value'])); ?>
                                                <?php endif; ?>
                                            </dd>
                                        <?php endforeach; ?>
                                    </dl>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($results['pages'] > 1) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo wp_kses_post(
                    paginate_links([
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'current'   => (int) $results['page'],
                        'total'     => (int) $results['pages'],
                        'prev_text' => __('&laquo; Previous', 'github-sync'),
                        'next_text' => __('Next &raquo;', 'github-sync'),
                    ])
                );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
