<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * Every GraphQL document this module sends, in one place, so each field can be checked against
 * Braintree's published schema.
 */
final class Queries
{
    private const CARD = '... on CreditCardDetails { bin brandCode last4 expirationMonth expirationYear }';

    private const TRANSACTION = 'fragment StacklessTransaction on Transaction {
  id
  legacyId
  status
  orderId
  merchantAccountId
  amount { value currencyCode }
  upcomingRetryDate
  retriedTransactions { id status }
  refunds { id status }
  processorAuthorizationResponse { legacyCode }
  statusHistory {
    status
    ... on ProcessorDeclinedEvent { declineType }
    ... on GatewayRejectedEvent { gatewayRejectionReason }
  }
  paymentMethod {
    id
    legacyId
    usage
    customer { id }
    details { __typename ' . self::CARD . ' }
  }
}';

    public const CHARGE = 'mutation ($input: ChargeCreditCardInput!) {
  chargeCreditCard(input: $input) { transaction { ...StacklessTransaction } }
}
' . self::TRANSACTION;

    public const FIND = 'query ($input: TransactionSearchInput!, $first: Int) {
  transactions(input: $input, first: $first) { edges { node { ...StacklessTransaction } } }
}
' . self::TRANSACTION;

    public const READ = 'query ($id: ID!) {
  node(id: $id) { ...StacklessTransaction }
}
' . self::TRANSACTION;

    /** The transaction with the money that went back since. */
    public const HISTORY = 'query ($id: ID!) {
  node(id: $id) {
    ... on Transaction {
      id
      legacyId
      status
      orderId
      amount { value currencyCode }
      refunds { id status orderId amount { value currencyCode } }
    }
  }
}';

    /** On its own, because a key can lack the permission for disputes and still refund. */
    public const TRANSACTION_DISPUTES = 'query ($id: ID!) {
  node(id: $id) {
    ... on Transaction {
      id
      disputes { id status type amountDisputed { value currencyCode } }
    }
  }
}';

    public const PAYMENT_METHOD = 'query ($id: ID!) {
  node(id: $id) {
    ... on PaymentMethod {
      id
      legacyId
      usage
      details {
        __typename
        ... on CreditCardDetails {
          bin
          brandCode
          last4
          expirationMonth
          expirationYear
          threeDSecure { authentication { authenticationStatus liabilityShifted } }
        }
      }
    }
  }
}';

    public const MERCHANT = 'query ($first: Int, $after: String) {
  viewer {
    merchant {
      id
      merchantAccounts(first: $first, after: $after) {
        edges {
          node {
            id
            currencyCode
            status
            isDefault
            threeDSecure { v2 { supportedCardBrands } }
          }
        }
        pageInfo { hasNextPage endCursor }
      }
    }
  }
}';

    public const CLIENT_TOKEN = 'mutation ($input: CreateClientTokenInput) {
  createClientToken(input: $input) { clientToken }
}';

    public const CREATE_CUSTOMER = 'mutation ($input: CreateCustomerInput) {
  createCustomer(input: $input) { customer { id } }
}';

    public const VAULT = 'mutation ($input: VaultCreditCardInput!) {
  vaultCreditCard(input: $input) {
    paymentMethod {
      id
      legacyId
      usage
      customer { id }
      details { __typename ' . self::CARD . ' }
    }
    verification { status gatewayRejectionReason processorResponse { legacyCode } }
  }
}';

    public const DELETE_CARD = 'mutation ($input: DeletePaymentMethodFromVaultInput!) {
  deletePaymentMethodFromVault(input: $input) { clientMutationId }
}';

    public const REFUND = 'mutation ($input: RefundTransactionInput!) {
  refundTransaction(input: $input) { refund { id status amount { value currencyCode } } }
}';

    public const REVERSE = 'mutation ($input: ReverseTransactionInput!) {
  reverseTransaction(input: $input) {
    reversal {
      __typename
      ... on Transaction { id status }
      ... on Refund { id status amount { value currencyCode } }
    }
  }
}';

    public const LEGACY_IDS = 'query ($input: IdsFromLegacyIdsInput!) {
  idsFromLegacyIds(input: $input)
}';

    public const REFUNDED_TRANSACTION = 'query ($id: ID!) {
  node(id: $id) { ... on Refund { id refundedTransaction { id } } }
}';

    public const RECENT_REFUNDS = 'query ($input: RefundSearchInput!, $first: Int, $after: String) {
  refunds(input: $input, first: $first, after: $after) {
    edges { node { id refundedTransaction { id } } }
    pageInfo { hasNextPage endCursor }
  }
}';

    public const RECENT_TRANSACTIONS = 'query ($input: TransactionSearchInput!, $first: Int, $after: String) {
  transactions(input: $input, first: $first, after: $after) {
    edges { node { id } }
    pageInfo { hasNextPage endCursor }
  }
}';

    public const RECENT_DISPUTES = 'query ($input: DisputeSearchInput!, $first: Int, $after: String) {
  disputes(input: $input, first: $first, after: $after) {
    edges { node { id transaction { id } } }
    pageInfo { hasNextPage endCursor }
  }
}';
}
