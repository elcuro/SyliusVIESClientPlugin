<?php

declare(strict_types=1);

namespace Prometee\SyliusVIESClientPlugin\Applicator;

use Prometee\SyliusVIESClientPlugin\Entity\EuropeanChannelAwareInterface;
use Prometee\SyliusVIESClientPlugin\Entity\VATNumberAwareInterface;
use Prometee\VIESClient\Util\VatNumberUtil;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Taxation\Applicator\OrderTaxesApplicatorInterface;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;
use Sylius\Component\Order\Model\AdjustmentInterface as OrderAdjustmentInterface;

final class OrderEuropeanVATNumberApplicator implements OrderTaxesApplicatorInterface
{
    public const SUBSTRACTED_TAX = 'substracted_tax';

    /**
     * @var AdjustmentFactoryInterface
     */
    private $adjustmentFactory;

    public function __construct(AdjustmentFactoryInterface $adjustmentFactory)
    {
        $this->adjustmentFactory = $adjustmentFactory;
    }

    /**
     * {@inheritdoc}
     *
     * If an order contains a valid VAT number,
     * count out a VAT via adjustments
     */
    public function apply(OrderInterface $order, ZoneInterface $zone): void
    {
        /** @var EuropeanChannelAwareInterface $channel */
        $channel = $order->getChannel();
        /** @var VATNumberAwareInterface $billingAddress */
        $billingAddress = $order->getBillingAddress();

        $order->removeAdjustmentsRecursively(self::SUBSTRACTED_TAX);

        if (
            $channel !== null
            && $channel->getEuropeanZone() !== null
            && $channel->getBaseCountry() !== null
            && $billingAddress !== null
        ) {
            // These weird assignment is required for PHPStan
            $billingCountryCode = $order->getBillingAddress()->getCountryCode();

            if ($this->isValidForZeroEuropeanVAT($billingAddress, $billingCountryCode, $zone, $channel)) {
                $substractTaxesSum = $this->sumTaxes($order);

                $substractTaxAdjustment = $this->adjustmentFactory
                    ->createWithData(self::SUBSTRACTED_TAX, '', $substractTaxesSum);
                $order->addAdjustment($substractTaxAdjustment);

                $order->removeAdjustmentsRecursively(AdjustmentInterface::TAX_ADJUSTMENT);
            }

        }
    }

    /**
     * @param VATNumberAwareInterface $billingAddress
     * @param string|null $billingCountryCode
     * @param ZoneInterface $zone
     * @param EuropeanChannelAwareInterface $channel
     *
     * @return bool
     */
    public function isValidForZeroEuropeanVAT(
        VATNumberAwareInterface $billingAddress,
        ?string $billingCountryCode,
        ZoneInterface $zone,
        EuropeanChannelAwareInterface $channel
    ): bool {
        if ($billingAddress->hasVatNumber()) {
            $vatNumberArr = VatNumberUtil::split($billingAddress->getVatNumber());
            if (
                $vatNumberArr !== null
                && $zone === $channel->getEuropeanZone()
                && $channel->getBaseCountry() !== null
                && $channel->getBaseCountry()->getCode() !== $billingCountryCode
                && $billingCountryCode !== null
                && $billingCountryCode === $vatNumberArr[0]
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sum an order not neutral taxes
     */
    private function sumTaxes(OrderInterface $order): int
    {
        $taxAdjustments = $order->getAdjustmentsRecursively(
            AdjustmentInterface::TAX_ADJUSTMENT
        );

        $substractTaxes = $taxAdjustments->map(
            function (OrderAdjustmentInterface $adjustment) {
                if ($adjustment->isNeutral()) {
                    return $adjustment->getAmount();
                }

                return 0;
            }
        );

        $tax = (int) array_sum($substractTaxes->toArray()) * -1;

        return $this->roundTax($order, $tax);
    }

    /**
     * Round tax amount
     *
     * because some currencies doesn't support amounts with decimals.
     * This could be problem with e-payments.
     */
    private function roundTax(OrderInterface $order, int $tax): int
    {
        $currency = $order->getCurrencyCode();

        if ($currency === 'RON' || $currency === 'HUF') {
            $tax = (int)round($tax, -2);
        }

        return $tax;
    }
}
