<?php

declare(strict_types=1);

namespace WPQuizStudio\Front;

use WPQuizStudio\Repository\CategoryRepository;
use WPQuizStudio\Repository\QuizRepository;

/** Renders a public, category-aware directory of active quizzes. */
final class Directory
{
    public function __construct(
        private QuizRepository $quizzes,
        private CategoryRepository $categories
    ) {
    }

    public function register(): void
    {
        add_shortcode('wp_quiz_studio_directory', [$this, 'shortcode']);
        add_shortcode('wp_quiz_studio_category', [$this, 'shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        wp_register_style('wpqs-directory', WPQS_URL . 'assets/directory.css', [], WPQS_VERSION);
    }

    public function shortcode(array $atts = []): string
    {
        $atts = shortcode_atts([
            'category' => '',
            'columns' => '3',
            'show_filters' => 'yes',
            'show_expiry' => 'yes',
            'target' => '_self',
        ], $atts, 'wp_quiz_studio_directory');

        $categoryId = 0;
        $categoryFound = true;
        $categoryValue = sanitize_text_field((string) $atts['category']);
        if ($categoryValue !== '') {
            if (ctype_digit($categoryValue)) {
                $categoryId = absint($categoryValue);
            } else {
                $category = $this->categories->findBySlug($categoryValue);
                $categoryId = (int) ($category['id'] ?? 0);
                $categoryFound = $categoryId > 0;
            }
        }

        $quizzes = $categoryFound ? $this->quizzes->publicDirectory($categoryId) : [];
        $categories = $this->categories->all(true);
        $columns = min(4, max(1, absint($atts['columns'])));
        $showFilters = $atts['show_filters'] === 'yes' && $categoryId === 0;
        $showExpiry = $atts['show_expiry'] === 'yes';
        $target = in_array($atts['target'], ['_self', '_blank'], true) ? $atts['target'] : '_self';

        wp_enqueue_style('wpqs-directory');

        ob_start();
        ?>
        <section class="wpqs-directory" style="--wpqs-directory-columns:<?php echo (int) $columns; ?>">
            <?php if ($showFilters && $categories !== []) : ?>
                <nav class="wpqs-directory-filters" aria-label="<?php esc_attr_e('Κατηγορίες quiz', 'wp-quiz-studio'); ?>">
                    <button type="button" class="is-active" data-wpqs-filter="all"><?php esc_html_e('Όλα', 'wp-quiz-studio'); ?></button>
                    <?php foreach ($categories as $category) : ?>
                        <?php if ((int) $category['quiz_count'] < 1) { continue; } ?>
                        <button type="button" data-wpqs-filter="<?php echo esc_attr((string) $category['slug']); ?>">
                            <?php echo esc_html((string) $category['name']); ?>
                            <small><?php echo (int) $category['quiz_count']; ?></small>
                        </button>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <div class="wpqs-directory-grid">
                <?php foreach ($quizzes as $quiz) :
                    $intro = (array) ($quiz['settings']['intro'] ?? []);
                    $category = is_array($quiz['category'] ?? null) ? $quiz['category'] : null;
                    $categorySlug = (string) ($category['slug'] ?? 'uncategorized');
                    $categoryColor = (string) ($category['color'] ?? '#d9bd85');
                    $categoryIcon = (string) ($category['icon'] ?? 'folder');
                    $image = (string) ($intro['image_url'] ?? '');
                    $expiresAt = (string) ($quiz['expires_at'] ?? '');
                    $quizType = (string) ($quiz['quiz_type'] ?? 'knowledge');
                    $typeLabels = [
                        'knowledge' => __('Quiz γνώσεων', 'wp-quiz-studio'),
                        'poll' => __('Δημοσκόπηση', 'wp-quiz-studio'),
                        'personality' => __('Τεστ προσωπικότητας', 'wp-quiz-studio'),
                        'survey' => __('Έρευνα', 'wp-quiz-studio'),
                    ];
                    ?>
                    <article class="wpqs-directory-card" data-wpqs-category="<?php echo esc_attr($categorySlug); ?>">
                        <?php if ($image !== '') : ?>
                            <img src="<?php echo esc_url($image); ?>" alt="" loading="lazy">
                        <?php endif; ?>
                        <div class="wpqs-directory-card-body">
                            <div class="wpqs-directory-badges">
                            <?php if ($category) : ?>
                                <span class="wpqs-category-badge" style="--wpqs-category-color:<?php echo esc_attr($categoryColor); ?>"><span aria-hidden="true" data-icon="<?php echo esc_attr($categoryIcon); ?>"></span><?php echo esc_html((string) $category['name']); ?></span>
                            <?php endif; ?>
                                <span class="wpqs-type-badge"><?php echo esc_html((string) ($typeLabels[$quizType] ?? $typeLabels['knowledge'])); ?></span>
                            </div>
                            <h3><?php echo esc_html((string) $quiz['title']); ?></h3>
                            <?php if ((string) $quiz['description'] !== '') : ?>
                                <p><?php echo esc_html(wp_trim_words(wp_strip_all_tags((string) $quiz['description']), 24)); ?></p>
                            <?php endif; ?>
                            <?php if ($showExpiry && $expiresAt !== '') : ?>
                                <p class="wpqs-expiry-label">
                                    <?php
                                    echo esc_html(sprintf(
                                        __('Διαθέσιμο έως %s', 'wp-quiz-studio'),
                                        wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($expiresAt . ' UTC'))
                                    ));
                                    ?>
                                </p>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(home_url('/wpqs-embed/' . (int) $quiz['id'] . '/')); ?>" target="<?php echo esc_attr($target); ?>" <?php echo $target === '_blank' ? 'rel="noopener"' : ''; ?>>
                                <?php esc_html_e('Έναρξη quiz', 'wp-quiz-studio'); ?>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>

                <?php if ($quizzes === []) : ?>
                    <p class="wpqs-directory-empty"><?php esc_html_e('Δεν υπάρχουν διαθέσιμα quiz σε αυτή την κατηγορία.', 'wp-quiz-studio'); ?></p>
                <?php endif; ?>
            </div>
        </section>
        <script>
        document.currentScript.previousElementSibling?.querySelectorAll('[data-wpqs-filter]').forEach(function(button){
            button.addEventListener('click', function(){
                var directory = button.closest('.wpqs-directory');
                var filter = button.getAttribute('data-wpqs-filter');
                directory.querySelectorAll('[data-wpqs-filter]').forEach(function(item){ item.classList.toggle('is-active', item === button); });
                directory.querySelectorAll('[data-wpqs-category]').forEach(function(card){
                    card.hidden = filter !== 'all' && card.getAttribute('data-wpqs-category') !== filter;
                });
            });
        });
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
