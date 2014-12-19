<?php
namespace FireflyIII\Database\PiggyBank;

use Carbon\Carbon;
use FireflyIII\Database\CommonDatabaseCalls;
use FireflyIII\Database\CUD;
use FireflyIII\Database\SwitchUser;
use FireflyIII\Exception\FireflyException;
use FireflyIII\Exception\NotImplementedException;
use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag;


/**
 * Class Piggybank
 *
 * @package FireflyIII\Database
 */
class PiggyBank implements CUD, CommonDatabaseCalls, PiggyBankInterface
{
    use SwitchUser;

    /**
     *
     */
    public function __construct()
    {
        $this->setUser(\Auth::user());
    }

    /**
     * @param \Eloquent $model
     *
     * @return bool
     */
    public function destroy(\Eloquent $model)
    {
        $model->delete();
    }

    /**
     * @param array $data
     *
     * @return \Eloquent
     * @throws FireflyException
     */
    public function store(array $data)
    {
        if (!isset($data['remind_me']) || (isset($data['remind_me']) && $data['remind_me'] == 0)) {
            $data['reminder'] = null;
        }
        $piggyBank = new \Piggybank($data);
        $piggyBank->save();

        return $piggyBank;
    }

    /**
     * @param \Eloquent $model
     * @param array     $data
     *
     * @return bool
     */
    public function update(\Eloquent $model, array $data)
    {
        /** @var \Piggybank $model */
        $model->name          = $data['name'];
        $model->account_id    = intval($data['account_id']);
        $model->targetamount  = floatval($data['targetamount']);
        $model->targetdate    = isset($data['targetdate']) && $data['targetdate'] != '' ? $data['targetdate'] : null;
        $model->rep_every     = intval($data['rep_every']);
        $model->reminder_skip = intval($data['reminder_skip']);
        $model->order         = intval($data['order']);
        $model->remind_me     = intval($data['remind_me']);
        $model->reminder      = isset($data['reminder']) ? $data['reminder'] : 'month';

        if ($model->remind_me == 0) {
            $model->reminder = null;
        }

        $model->save();

        return true;
    }

    /**
     * Validates an array. Returns an array containing MessageBags
     * errors/warnings/successes.
     *
     * Ignore PHPMD rules because Laravel 5.0 will make this method superfluous anyway.
     *
     * @SuppressWarnings("Cyclomatic")
     * @SuppressWarnings("NPath")
     * @SuppressWarnings("MethodLength")
     *
     * @param array $model
     *
     * @return array
     */
    public function validate(array $model)
    {
        $warnings  = new MessageBag;
        $successes = new MessageBag;
        $errors    = new MessageBag;

        /*
         * Name validation:
         */
        if (!isset($model['name'])) {
            $errors->add('name', 'Name is mandatory');
        }

        if (isset($model['name']) && strlen($model['name']) == 0) {
            $errors->add('name', 'Name is too short');
        }
        if (isset($model['name']) && strlen($model['name']) > 100) {
            $errors->add('name', 'Name is too long');
        }

        if (intval($model['account_id']) == 0) {
            $errors->add('account_id', 'Account is mandatory');
        }
        if ($model['targetdate'] == '' && isset($model['remind_me']) && intval($model['remind_me']) == 1) {
            $errors->add('targetdate', 'Target date is mandatory when setting reminders.');
        }
        if ($model['targetdate'] != '') {
            try {
                new Carbon($model['targetdate']);
            } catch (\Exception $e) {
                $errors->add('targetdate', 'Invalid date.');
            }
        }
        if (floatval($model['targetamount']) < 0.01) {
            $errors->add('targetamount', 'Amount should be above 0.01.');
        }
        if (!in_array(ucfirst($model['reminder']), \Config::get('firefly.piggybank_periods'))) {
            $errors->add('reminder', 'Invalid reminder period (' . $model['reminder'] . ')');
        }
        // check period.
        if (!$errors->has('reminder') && !$errors->has('targetdate') && isset($model['remind_me']) && intval($model['remind_me']) == 1) {
            $today  = new Carbon;
            $target = new Carbon($model['targetdate']);
            switch ($model['reminder']) {
                case 'week':
                    $today->addWeek();
                    break;
                case 'month':
                    $today->addMonth();
                    break;
                case 'year':
                    $today->addYear();
                    break;
            }
            if ($today > $target) {
                $errors->add('reminder', 'Target date is too close to today to set reminders.');
            }
        }

        $validator = \Validator::make($model, \Piggybank::$rules);
        if ($validator->invalid()) {
            $errors->merge($errors);
        }

        // add ok messages.
        $list = ['name', 'account_id', 'targetamount', 'targetdate', 'remind_me', 'reminder'];
        foreach ($list as $entry) {
            if (!$errors->has($entry) && !$warnings->has($entry)) {
                $successes->add($entry, 'OK');
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'successes' => $successes];
    }

    /**
     * Returns an object with id $id.
     *
     * @param int $id
     *
     * @return \Eloquent
     */
    public function find($id)
    {
        return \Piggybank::
        leftJoin('accounts', 'accounts.id', '=', 'piggybanks.account_id')->where('piggybanks.id', '=', $id)->where('accounts.user_id', $this->getUser()->id)
                         ->first(['piggybanks.*']);
    }

    /**
     * Finds an account type using one of the "$what"'s: expense, asset, revenue, opening, etc.
     *
     * @param $what
     *
     * @return \AccountType|null
     * @throws NotImplementedException
     */
    public function findByWhat($what)
    {
        // TODO: Implement findByWhat() method.
        throw new NotImplementedException;
    }

    /**
     * Returns all objects.
     *
     * @return Collection
     */
    public function get()
    {
        return $this->getUser()->piggybanks()->where('repeats', 0)->get();
    }

    /**
     * @param array $ids
     *
     * @return Collection
     * @throws NotImplementedException
     */
    public function getByIds(array $ids)
    {
        // TODO: Implement getByIds() method.
        throw new NotImplementedException;
    }

    /**
     * @param \Piggybank $piggybank
     * @param Carbon     $date
     *
     * @return mixed
     * @throws FireflyException
     * @throws NotImplementedException
     */
    public function findRepetitionByDate(\Piggybank $piggybank, Carbon $date)
    {
        /** @var Collection $reps */
        $reps = $piggybank->piggybankrepetitions()->get();
        if ($reps->count() == 1) {
            return $reps->first();
        }
        if ($reps->count() == 0) {
            throw new FireflyException('Should always find a piggy bank repetition.');
        }
        // should filter the one we need:
        $repetitions = $reps->filter(
            function (\PiggybankRepetition $rep) use ($date) {
                if ($date >= $rep->startdate && $date <= $rep->targetdate) {
                    return $rep;
                }
            }
        );
        if ($repetitions->count() == 0) {
            return null;
        }

        return $repetitions->first();
    }

    /**
     * @param \Account $account
     *
     * @return float
     */
    public function leftOnAccount(\Account $account)
    {
        $balance = \Steam::balance($account);
        /** @var \Piggybank $p */
        foreach ($account->piggybanks()->get() as $p) {
            $balance -= $p->currentRelevantRep()->currentamount;
        }

        return $balance;

    }
}