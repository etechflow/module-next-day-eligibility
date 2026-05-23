<?php

declare(strict_types=1);

namespace ETechFlow\NextDayEligibility\Console\Command;

use ETechFlow\NextDayEligibility\Model\EligibilityEvaluator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-shot CLI to re-evaluate next_day_eligible across the catalogue (v1.6.0).
 *
 *   bin/magento etechflow:nde:resync                  # all simple products
 *   bin/magento etechflow:nde:resync --sku=ABC,DEF    # specific SKUs only
 *   bin/magento etechflow:nde:resync --dry-run        # log what would change
 *
 * Use cases:
 *
 *   - Initial cleanup right after upgrading to v1.6.0 — flush any pre-existing
 *     stale rows in one go without waiting for the hourly cron.
 *   - Manual fix when a merchant has a specific known-stale SKU and doesn't
 *     want to wait for the next cron tick.
 *   - CI / health-check: dry-run to detect drift without writing.
 *
 * Idempotent — runs the same EligibilityEvaluator the observers / plugin /
 * cron use, so calling it any number of times converges to the correct
 * state without surprising side effects.
 */
class ResyncEligibilityCommand extends Command
{
    public function __construct(
        private readonly EligibilityEvaluator $evaluator,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:nde:resync')
            ->setDescription('Re-evaluate next_day_eligible across the catalogue. Idempotent.')
            ->addOption(
                'sku',
                's',
                InputOption::VALUE_REQUIRED,
                'Comma-separated SKUs to limit the resync to. Default: all simple products.'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Log what would change without writing. Useful for CI / health checks.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Set area so EAV attribute saves use the right scope when called from CLI
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }

        $skuOption = (string) $input->getOption('sku');
        $dryRun    = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('<comment>--dry-run mode: scanning + reporting only, no writes.</comment>');
            $output->writeln('<comment>(Note: the evaluator does not currently support dry-run inspection — this command reports the COUNT of products that would be evaluated.)</comment>');
            $output->writeln('');
        }

        $productIds = $this->resolveProductIds($skuOption, $output);
        if (empty($productIds)) {
            $output->writeln('<error>No products matched the filter. Nothing to do.</error>');
            return Command::FAILURE;
        }

        $total = count($productIds);
        $output->writeln("<info>Resyncing {$total} product(s)...</info>");
        $output->writeln('');

        if ($dryRun) {
            $output->writeln("<info>Would have evaluated {$total} product(s). Exit.</info>");
            return Command::SUCCESS;
        }

        $count = 0;
        $errors = 0;
        $start = microtime(true);

        foreach ($productIds as $productId) {
            try {
                $this->evaluator->evaluateById((int) $productId);
                $count++;
                if ($count % 100 === 0) {
                    $output->writeln("  ... {$count}/{$total} done");
                }
            } catch (\Throwable $e) {
                $errors++;
                $output->writeln(
                    "  <error>product_id={$productId} failed: {$e->getMessage()}</error>"
                );
            }
        }

        $elapsed = number_format(microtime(true) - $start, 2);
        $output->writeln('');
        $output->writeln("<info>Done. Evaluated: {$count}, errors: {$errors}, elapsed: {$elapsed}s.</info>");

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Resolve either a --sku=A,B,C list or the full catalogue (simple products only)
     * to an array of product IDs.
     *
     * @return int[]
     */
    private function resolveProductIds(string $skuOption, OutputInterface $output): array
    {
        if ($skuOption !== '') {
            $skus = array_filter(array_map('trim', explode(',', $skuOption)));
            $ids = [];
            foreach ($skus as $sku) {
                try {
                    $product = $this->productRepository->get($sku);
                    $ids[] = (int) $product->getId();
                } catch (NoSuchEntityException $e) {
                    $output->writeln("  <comment>SKU '{$sku}' not found — skipped.</comment>");
                }
            }
            return $ids;
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', 'simple');
        $collection->addAttributeToSelect('entity_id');

        $ids = [];
        foreach ($collection as $product) {
            $ids[] = (int) $product->getId();
        }
        return $ids;
    }
}
