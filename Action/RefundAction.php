<?php

namespace Payum\Paypal\Rest\Action;

use PayPal\Api\Amount;
use PayPal\Api\DetailedRefund;
use PayPal\Api\Payment as PaypalPayment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RefundRequest;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\GatewayInterface;
use Payum\Core\Request\Capture;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Refund;
use PayPal\Api\Payment;
use PayPal\Api\Sale;

class RefundAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{

    use ApiAwareTrait;
    use GatewayAwareTrait;

    public function __construct()
    {
        $this->apiClass = ApiContext::class;
    }

    /**
     * @param mixed $request
     *
     * @throws \Payum\Core\Exception\RequestNotSupportedException if the action dose not support the request.
     */
    public function execute($request)
    {
        /** @var $request Refund */
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaypalPayment $model */
        $model = $request->getModel();

        $transactions     = $model->getTransactions();
        $relatedResources = $transactions[0]->getRelatedResources();
        $originalSale     = $relatedResources[0]->getSale();
        $originalSaleId = $originalSale->getId();

        try {
            $sale = Sale::get($originalSaleId, $this->api);
        } catch (\Exception $ex) {
            throw new LogicException(
                sprintf(
                    'Original sale %s could not be retrieved',
                    $originalSaleId
                )
            );
        }

        $originalAmt = $originalSale->getAmount();

        // Amount-details have to be left out from the refund amount
        $amt = new Amount();
        $amt->setCurrency($originalAmt->getCurrency());
        $amt->setTotal($originalAmt->getTotal());

        $refundRequest = new RefundRequest();
        $refundRequest->setAmount($amt);

        $sale = new Sale();
        $sale->setId($originalSaleId);

        try {
            // Refund the sale
            // (See bootstrap.php for more on `ApiContext`)
            /** @var DetailedRefund $refundedSale */
            $refundedSale = $sale->refundSale($refundRequest, $this->api);

            $request->setModel($refundedSale);
        } catch (PayPalConnectionException $ex) {
            throw new LogicException(
                sprintf(
                    '%s %s',
                    $ex->getMessage(),
                    $ex->getData()
                )
            );
        } catch (\Exception $ex) {
            throw new LogicException(
                $ex->getMessage(),
                $ex->getCode(),
                $ex
            );
        }
    }

    /**
     * @param mixed $request
     *
     * @return boolean
     */
    public function supports($request)
    {
        return
            $request instanceof Refund &&
            $request->getModel() instanceof PaypalPayment;
    }
}
