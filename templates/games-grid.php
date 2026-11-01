<?php
/**
 * The slots grid: every game the site has a page for, biggest win first. Each card links to the game's page;
 * "Biggest win" and "Last win" show the win's details on hover, focus or tap.
 * Override: copy to <theme>/wintracker/games-grid.php.
 *
 * @var array<int, array{rank: int, url: string, name: string, provider: array<string, mixed>|null, image: string, biggest: array<string, mixed>, last: array<string, mixed>|null, hidden: bool}> $items
 * @var int    $more      games each "Show more" reveals (0: no button)
 * @var bool   $rank      show #1, #2 …
 * @var string $currency  eur | original
 * @var string $empty
 */

defined( 'ABSPATH' ) || exit;

if ( ! $items ) {
	echo '<p class="wintracker__empty">' . esc_html( $empty ) . '</p>';
	return;
}
$wt_amount = static function ( array $win ) use ( $currency ) {
	return Wintracker_Format::win_cell( $win, 'win', $currency );
};
$wt_hidden = count( array_filter( array_column( $items, 'hidden' ) ) );
?>
<ul class="wintracker__gg">
	<?php foreach ( $items as $item ) : ?>
		<li class="wintracker__gg-card"<?php echo $item['hidden'] ? ' hidden' : ''; ?>>
			<div class="wintracker__gg-thumb">
				<?php echo $item['image']; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_get_attachment_image() / escaped img ?>
				<?php if ( $rank ) : ?>
					<span class="wintracker__gg-rank">#<?php echo (int) $item['rank']; ?></span>
				<?php endif; ?>
			</div>
			<div class="wintracker__gg-body">
				<h4 class="wintracker__gg-name"><a class="wintracker__gg-link" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a></h4>
				<?php if ( ! empty( $item['provider']['name'] ) ) : ?>
					<p class="wintracker__gg-provider"><?php echo Wintracker_Links::wrap( 'provider', $item['provider']['id'] ?? 0, esc_html( $item['provider']['name'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
				<?php endif; ?>
				<dl class="wintracker__gg-stats">
					<div class="wintracker__gg-stat wintracker__gg-big" tabindex="0">
						<dt><?php echo Wintracker_Format::label( 'biggest_win' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="wintracker__gg-i" aria-hidden="true">i</span></dt>
						<dd><?php echo $wt_amount( $item['biggest'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="wintracker__gg-tip" role="tooltip"><?php echo Wintracker_Format::win_tip( $item['biggest'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></dd>
					</div>
					<?php if ( $item['last'] ) : ?>
						<div class="wintracker__gg-stat wintracker__gg-last" tabindex="0">
							<dt><?php echo Wintracker_Format::label( 'last_win' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="wintracker__gg-i" aria-hidden="true">i</span></dt>
							<dd><?php echo Wintracker_Format::time_ago( $item['last']['won_at'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput ?><small><?php echo $wt_amount( $item['last'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></small><span class="wintracker__gg-tip" role="tooltip"><?php echo Wintracker_Format::win_tip( $item['last'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></dd>
						</div>
					<?php endif; ?>
				</dl>
			</div>
		</li>
	<?php endforeach; ?>
</ul>
<?php if ( $more && $wt_hidden ) : ?>
	<p class="wintracker__gg-more">
		<button type="button" class="wintracker__gg-more-btn" data-step="<?php echo (int) $more; ?>">
			<?php
			/* translators: %s: number of games not shown yet */
			echo esc_html( sprintf( __( 'Show more (%s)', 'wintracker' ), number_format_i18n( $wt_hidden ) ) );
			?>
		</button>
	</p>
<?php endif; ?>
