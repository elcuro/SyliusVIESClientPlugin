<?php

declare(strict_types=1);

namespace Prometee\SyliusVIESClientPlugin\Applicator;

use Doctrine\Common\Collections\ArrayCollection;
use Prometee\SyliusVIESClientPlugin\Entity\EuropeanChannelAwareInterface;
use Prometee\SyliusVIESClientPlugin\Entity\VATNumberAwareInterface;
use Prometee\VIESClient\Util\VatNumberUtil;
use Sylius\Component\Addressing\Matcher\ZoneMatcherInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;
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

    /**
     * @var ZoneMatcherInterface
     */
    private $zoneMatcher;

    public function __construct(
        AdjustmentFactoryInterface $adjustmentFactory,
        ZoneMatcherInterface $zoneMatcher
    ) {
        $this->adjustmentFactory = $adjustmentFactory;
        $this->zoneMatcher = $zoneMatcher;
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

        if (
            $channel !== null
            && $channel->getEuropeanZone() !== null
            && $channel->getBaseCountry() !== null
            && $billingAddress !== null
        ) {
            $billingCountryCode = $order->getBillingAddress()->getCountryCode();

            if (!$this->isValidForZeroEuropeanVAT(
                $billingAddress,
                $billingCountryCode,
                $channel
            )) {
                return;
            }

            $adjustments = $order->getAdjustmentsRecursively(AdjustmentInterface::TAX_ADJUSTMENT);

            foreach ($adjustments as $adjustment) {
                $amount = $adjustment->getAmount() * -1;
                $isNeutral = false;

                $zeroVatAdjustment = $this->adjustmentFactory->createWithData(
                    AdjustmentInterface::TAX_ADJUSTMENT,
                    '0% DPH',
                    $amount,
                    $isNeutral
                );

                $adjustable = $adjustment->getAdjustable();
                $adjustable->addAdjustment($zeroVatAdjustment);
            }

            $order->recalculateAdjustmentsTotal();

        }
    }

    private function isValidForZeroEuropeanVAT(
        VATNumberAwareInterface $billingAddress,
        ?string $billingCountryCode,
        EuropeanChannelAwareInterface $channel
    ): bool {
        if (!$billingAddress->hasVatNumber()) {
            return false;
        }

        $vatNumberArr = VatNumberUtil::split($billingAddress->getVatNumber());
        if ($vatNumberArr === null) {
            return false;
        }

        if ($this->isBillingAddressInEuropeanZone($billingAddress, $channel->getEuropeanZone())
            && $channel->getBaseCountry() !== null
            && $channel->getBaseCountry()->getCode() !== $billingCountryCode
            && $billingCountryCode !== null
            && $billingCountryCode === $vatNumberArr[0]
        ) {
            return true;
        }

        return false;
    }

    private function isBillingAddressInEuropeanZone(
        AddressInterface $billingAddress,
        ZoneInterface $euZone
    ): bool {
        $zones = $this->zoneMatcher->matchAll($billingAddress);

        foreach ($zones as $zone) {
            if ($zone === $euZone) {
                return true;
            }
        }

        return false;
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
