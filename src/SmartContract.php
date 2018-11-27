<?php

namespace Ethereum;

use Ethereum\Abi\Abi;
use Ethereum\Abi\Structs\StructEvent;
use Ethereum\Abi\Structs\StructFunction;
use Ethereum\Types\Address;
use Ethereum\Types\Byte;
use Ethereum\Types\Event;
use Ethereum\Types\Log;
use Ethereum\Types\RawTransactionReceipt;
use Ethereum\Types\Transaction;
use Ethereum\Types\BlockNumber;
use BadMethodCallException;
use Ethereum\Types\Uint;
use Exception;

class SmartContract
{
    /**
     * @var Abi
     */
    private $abi;

    /**
     * @var Address
     */
    private $address;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var array
     */
    private $eventListeners = [];

    /**
     * @param Client $client
     * @param string $address
     * @param string $abi
     * @throws Exception
     */
    public function __construct(Client $client, string $address, string $abi)
    {
        $this->address = Address::init($address);
        $this->abi     = new Abi($abi);
        $this->client  = $client;
    }

    /**
     * @return  Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @return Address
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * @param string $name
     * @return StructFunction
     */
    public function getFunction(string $name): StructFunction
    {
        if (! isset($this->abi->{$name}) or $this->abi->{$name}->type !== 'function') {
            throw new BadMethodCallException('Call to undefined contract function: ' . $name);
        }
        return $this->abi->{$name};
    }

    /**
     * @param string $name
     * @return StructEvent
     */
    public function getEvent(string $name): StructEvent
    {
        if (! isset($this->abi->{$name}) or $this->abi->{$name}->type !== 'event') {
            throw new BadMethodCallException('Call to undefined contract event: ' . $name);
        }
        return $this->abi->{$name};
    }

    /**
     * @param string $eventName
     * @param callable $eventHandler
     * @return string
     */
    public function watch(string $eventName, callable $eventHandler)
    {
        $id = spl_object_hash((object)$eventHandler);
        $this->eventListeners[$eventName][$id] = $eventHandler;
        return $id;
    }

    /**
     * @param Log $log
     * @throws Exception
     */
    public function dispatch(Log $log): void
    {
        $topic = count($log->topics) > 0 ? $log->topics[0] : null;
        if (empty($topic)) {
            return;
        }
        $event = $this->getEvent($topic->toString());
        if (isset($this->eventListeners[$event->name])) {
            $data = $event->deserialize($log);
            foreach ($this->eventListeners[$event->name] as $listener) {
                $listener(new Event($event, $log, $data));
            }
        }
    }

    /**
     * @param string $eventName
     * @param string $id
     * @return $this
     */
    public function unwatch(string $eventName, string $id)
    {
        unset($this->eventListeners[$eventName][$id]);
        return $this;
    }

    /**
     * @param string $functionName
     * @param array $arguments
     * @param Uint|null $gasPrice
     * @param Uint|null $nonce
     * @return mixed
     * @throws Exception
     */
    public function call(string $functionName, array $arguments, ?Uint $gasPrice = null, ?Uint $nonce = null)
    {
        $function = $this->getFunction($functionName);
        $data     = Byte::initWithHex($function->getSignature() . $function->inputs->serialize($arguments));

        if ($function->constant) {
            return $this->callConstantFunction($function, $data);
        } else {
            return $this->callNonConstantFunction($function, $data, $gasPrice, $nonce);
        }
    }

    /**
     * @param StructFunction $function
     * @param Byte $data
     * @return array
     * @throws Exception
     */
    protected function callConstantFunction(StructFunction $function, Byte $data)
    {
        $key = $this->client->keystore()->getNextKey();
        $transaction = new Transaction(
            $key->address,
            $this->address,
            $data
        );
        $result = $this->client->eth()->call($transaction, BlockNumber::init(BlockNumber::PENDING));
        return $function->outputs->deserialize($result);
    }

    /**
     * @param StructFunction $function
     * @param Byte $data
     * @param Uint|null $gasPrice
     * @param Uint|null $nonce
     * @return RawTransactionReceipt
     * @throws Exception
     */
    protected function callNonConstantFunction(StructFunction $function, Byte $data, ?Uint $gasPrice = null, ?Uint $nonce = null)
    {
        if ($function->payable) {
            // @todo
            throw new Exception('Can not call payable function.');
        }
        $key = $this->client->keystore()->getNextKey();
        // query gas price
        $gasPrice = $gasPrice ?? ($this->client->gasPrice ?? $this->client->eth()->gasPrice());
        // create transaction
        $transaction = new Transaction(
            $key->address,
            $this->address,
            $data,
            null,
            $gasPrice
        );
        // query gas
        $transaction->gas   = $this->client->gasLimit ?? $this->client->eth()->estimateGas($transaction);
        // query nonce
        $transaction->nonce = empty($nonce) ? $this->client->eth()->getTransactionCount($key->address, BlockNumber::init(BlockNumber::PENDING)) : $nonce;
        // sign transaction
        $rawTransaction = $key->signTransaction($transaction, $this->client->chainId);

        $transactionHash = $this->client->eth()->sendRawTransaction($rawTransaction);

        return new RawTransactionReceipt(
            $transactionHash,
            $transaction
        );
    }
}