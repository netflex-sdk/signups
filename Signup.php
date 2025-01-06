<?php

namespace Netflex\Signups;

use Exception;
use Throwable;
use JsonSerializable;

use Brick\PhoneNumber\PhoneNumber;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Collection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

use Netflex\Query\Facades\Search;
use Netflex\API\Facades\API;
use Netflex\Commerce\Order;
use Netflex\Commerce\Contracts\Order as OrderContract;
use Netflex\Structure\Entry;
use Netflex\Structure\Model;
use Netflex\Customers\Customer;

class Signup implements JsonSerializable, Jsonable
{
    use Notifiable;

    protected object $signup;
    protected $model = Entry::class;
    protected $customerModel = Customer::class;

    public function __construct($signup)
    {
        $this->signup = $signup;
    }

    public function phone_countrycode()
    {
        if (!Str::startsWith($this->signup->phone, '+')) {
            return '47';
        }

        try {
            $parsed = PhoneNumber::parse($this->signup->phone, 'NO');
            return $parsed->getCountryCode();
        } catch (Exception $e) {
            return null;
        }

        return null;
    }

    public function entry()
    {
        return ($this->model)::forceFind($this->signup->entry_id);
    }

    public function customer(): ?Authenticatable
    {
        return ($this->customerModel)::find($this->signup->customer_id);
    }

    public function order(): ?OrderContract
    {
        return Order::retrieve($this->order_id);
    }

    public function name(): ?string
    {
        return trim($this->signup->firstname . ' ' . $this->signup->surname);
    }

    public function __get($key)
    {
        try {
            if (method_exists($this, $key)) {
                return $this->{$key}();
            }

            if (property_exists($this->signup, $key)) {
                return $this->signup->{$key};
            }

            if (property_exists($this->signup->data, $key)) {
                return $this->signup->data->{$key};
            }
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function find($id)
    {
        if ($signup = API::get('relations/signups/' . $id)) {
            return new static($signup);
        }
    }

    public static function resolve($code)
    {
        return new static(API::get('relations/signups/code/' . $code));
    }

    public static function all(Model $model): Collection
    {
        return collect(API::get('relations/signups'))
            ->mapInto(static::class);
    }

    public static function forEntry(Model $model): Collection
    {
        return collect(API::get('relations/signups/entry/' . $model->id))
            ->map(function ($signup) use ($model) {
                $signup = new static($signup);
                $signup->model = get_class($model);
                return $signup;
            });
    }

    public static function forOrder(OrderContract $order): Collection
    {
        return collect(API::get('relations/signups/order/' . $order->getOrderId()))
            ->mapInto(static::class);
    }

    public static function countForEntry(Model $model): int
    {
        return API::get('relations/signups/count/' . $model->id) ?? 0;
    }

    public static function user(Authenticatable $user)
    {
        return collect(API::get('relations/signups/customer/' . $user->getAuthIdentifier()))->map(function ($signup) {
            return new static($signup);
        })->filter(function (Signup $signup) {
            return $signup->entry() !== null;
        })->values();
    }

    public static function query(string $query)
    {
        $results = Search::relation('signup')
            ->ignorePublishingStatus()
            ->limit(1000)
            ->raw($query)
            ->fetch();

        if (count($results['data'])) {
            return collect($results['data'])
                ->map(function ($signup) {
                    return new static((object) $signup);
                });
        }

        return null;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->signup;
    }

    public function toJSON($options = 0)
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    public static function create(array $payload = [])
    {
        $response = API::post('relations/signups', $payload);
        return static::find($response->signup_id);
    }

    public static function createForEntry(Model $model, array $payload = [])
    {
        $payload['entry_id'] = $model->id;
        return static::create($payload);
    }

    public function delete()
    {
        return API::delete('relations/signups/' . $this->signup->id);
    }
}
