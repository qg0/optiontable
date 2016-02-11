<?php
/**
 * Author: Vehsamrak
 * Date Created: 06.02.16 17:43
 */

namespace OptionBundle\Service;

use Doctrine\ORM\EntityManager;
use OptionBundle\Entity\Futures;
use OptionBundle\Entity\OptionContract;
use OptionBundle\Entity\OptionPrice;
use OptionBundle\Entity\Symbol;
use OptionBundle\Exception\WrongMonth;
use OptionBundle\Repository\FuturesRepository;
use OptionBundle\Repository\OptionContractRepository;
use OptionBundle\Repository\OptionPriceRepository;
use simple_html_dom;
use Sunra\PhpSimple\HtmlDomParser;

class PriceCollector
{

    /**
     * @var \DateTime
     */
    private $dateTime;

    /**
     * @var FuturesRepository
     */
    private $futuresRepository;

    /**
     * @var OptionContractRepository
     */
    private $optionContractRepository;

    /**
     * @var EntityManager
     */
    private $em;
    /**
     * @var OptionPriceRepository
     */
    private $optionPriceRepository;

    /**
     * @param \DateTime $dateTime
     */
    public function __construct(
        FuturesRepository $futuresRepository,
        OptionContractRepository $optionContractRepository,
        OptionPriceRepository $optionPriceRepository
    ) {
        $this->em = $futuresRepository->getEntityManager();
        $this->dateTime = new \DateTime();
        $this->futuresRepository = $futuresRepository;
        $this->optionContractRepository = $optionContractRepository;
        $this->optionPriceRepository = $optionPriceRepository;
    }

    /**
     * @param \DateTime $dateTime
     */
    public function setDateTime($dateTime)
    {
        $this->dateTime = $dateTime;
    }

    /**
     * @param Symbol $symbol
     * @param int    $monthNumber
     * @throws WrongMonth
     */
    public function saveOptionPrices(Symbol $symbol, int $monthNumber)
    {
        $optionPrices = array_filter($this->collectOptionPrices($symbol, $monthNumber));

        foreach ($optionPrices as $optionPrice) {
            $this->em->persist($optionPrice);
        }

        $this->em->flush();
    }

    /**
     * @param Symbol $symbol
     * @param int    $monthNumber
     * @throws WrongMonth
     * @return OptionPrice[]
     */
    private function collectOptionPrices(Symbol $symbol, int $monthNumber): array
    {
        if ($monthNumber < 1 || $monthNumber > 12) {
            throw new WrongMonth();
        }

        $optionTableUrl = $this->createOptionTableUrl($symbol, $monthNumber);
        /** @var simple_html_dom $optionHtml */
        $optionHtml = HtmlDomParser::file_get_html($optionTableUrl);

        $pageError = $optionHtml->find('span.error');

        if (count($pageError)) {
            return [];
        }

        $futuresDataUrl = $this->createFuturesDataUrl($symbol, $monthNumber);
        /** @var simple_html_dom $optionHtml */
        $futuresDataHtml = HtmlDomParser::file_get_html($futuresDataUrl);

        $futuresExpirationDate = strip_tags($optionHtml->find('#divContent table table tr td')[1]->text());

        $futuresPrice = (float) $futuresDataHtml->find('#dtaLast')[0]->text();

        $futuresPrice52WeekNode = $futuresDataHtml->find('#main-content tr td td span strong');
        $futuresPrice52WeekHigh = (float) $futuresPrice52WeekNode[0]->text();
        $futuresPrice52WeekLow = (float) $futuresPrice52WeekNode[1]->text();

        $priceLines = $optionHtml->find('.datatable_simple tr');
        $priceLinesCount = count($priceLines);

        $futures = $this->futuresRepository->findOneBySymbolAndExpiration($symbol, $futuresExpirationDate);

        if (!$futures) {
            $futures = new Futures($symbol, $futuresExpirationDate);
            $this->futuresRepository->persist($futures);
            $this->futuresRepository->flush();
        }

        $prices = [];
        foreach ($priceLines as $priceLineKey => $priceLine) {
            if ($priceLineKey < 2 || $priceLineKey > $priceLinesCount - 5) {
                continue;
            }

            $priceLineNodes = $priceLine->children;

            $callPriceNode = $priceLineNodes[0];
            $strikeNode = $priceLineNodes[3];
            $putPriceNode = $priceLineNodes[4];

            $strike = (float) $strikeNode->text();
            $callPrice = (float) $callPriceNode->text();
            $putPrice = (float) $putPriceNode->text();

            if ($callPrice > 0) {
                $prices[] = $this->createOptionPrice(
                    OptionContract::TYPE_CALL,
                    $callPrice,
                    $strike,
                    $futuresPrice,
                    $futuresPrice52WeekHigh,
                    $futuresPrice52WeekLow,
                    $futures
                );
            }

            if ($putPrice > 0) {
                $prices[] = $this->createOptionPrice(
                    OptionContract::TYPE_PUT,
                    $putPrice,
                    $strike,
                    $futuresPrice,
                    $futuresPrice52WeekHigh,
                    $futuresPrice52WeekLow,
                    $futures
                );
            }
        }

        $optionHtml->clear();
        $futuresDataHtml->clear();
        unset($optionHtml, $futuresDataHtml);

        return $prices;
    }

    /**
     * @param Symbol $symbol
     * @param int    $monthNumber
     * @return string
     */
    private function createOptionTableUrl(Symbol $symbol, int $monthNumber): string
    {
        $yearOfContract = $this->getYearOfContract($monthNumber);

        $optionTableUrl = sprintf(
            'http://www.barchart.com/commodityfutures/null/options/%s%s%d?view=split&mode=i',
            $symbol->getSymbol(),
            $this->getMonthLetter($monthNumber),
            $yearOfContract
        );

        return $optionTableUrl;
    }

    /**
     * @param int $monthNumber
     * @return int
     */
    private function getYearOfContract(int $monthNumber): int
    {
        $currentMonth = (int) $this->dateTime->format('n');
        $currentYear = (int) $this->dateTime->format('y');
        $yearOfContract = ($currentMonth > $monthNumber) ? $currentYear + 1 : $currentYear;

        return $yearOfContract;
    }

    /**
     * @param int $monthNumber
     * @return string
     */
    private function getMonthLetter(int $monthNumber): string
    {
        $monthLetters = [
            1  => 'F',
            2  => 'G',
            3  => 'H',
            4  => 'J',
            5  => 'K',
            6  => 'M',
            7  => 'N',
            8  => 'Q',
            9  => 'U',
            10 => 'V',
            11 => 'X',
            12 => 'Z',
        ];

        return $monthLetters[$monthNumber];
    }

    /**
     * @param Symbol $symbol
     * @param int    $monthNumber
     * @return string
     */
    private function createFuturesDataUrl(Symbol $symbol, int $monthNumber): string
    {
        $yearOfContract = $this->getYearOfContract($monthNumber);

        $futuresDataUrl = sprintf(
            'http://www.barchart.com/quotes/futures/%s%s%d',
            $symbol->getSymbol(),
            $this->getMonthLetter($monthNumber),
            $yearOfContract
        );

        return $futuresDataUrl;
    }

    /**
     * @param string  $type
     * @param float   $price
     * @param float   $strike
     * @param float   $futuresPrice
     * @param float   $futuresPrice52WeekHigh
     * @param float   $futuresPrice52WeekLow
     * @param Futures $futures
     * @return OptionPrice|null
     */
    private function createOptionPrice(
        string $type,
        float $price,
        float $strike,
        float $futuresPrice,
        float $futuresPrice52WeekHigh,
        float $futuresPrice52WeekLow,
        Futures $futures
    ) {
        $optionContract = $this->getOptionContract($type, $futures, $strike);

        $optionPrice = $this->optionPriceRepository
            ->findOneForLastHourByOptionId($optionContract->getId());

        if ($optionPrice) {
            return null;
        }

        return new OptionPrice(
            $this->dateTime,
            $price,
            $optionContract,
            $futuresPrice,
            $futuresPrice52WeekHigh,
            $futuresPrice52WeekLow,
            $futures
        );
    }

    /**
     * @param string  $optionType
     * @param Futures $futures
     * @param float   $strike
     * @return OptionContract
     */
    private function getOptionContract(
        string $optionType,
        Futures $futures,
        float $strike
    ): OptionContract
    {
        $optionContract = $this->optionContractRepository
            ->findOneByTypeFuturesAndStrike($optionType, $futures->getId(), $strike);

        if (!$optionContract) {
            $optionContract = new OptionContract($optionType, $futures, $strike);
            $this->optionContractRepository->persist($optionContract);
            $this->optionContractRepository->flush();
        }

        return $optionContract;
    }
}
