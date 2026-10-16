<?php

namespace Brigada\Hq\Jobs;

/** Thrown inside the job so the queue retries it. Never reported back to HQ. */
class DeliveryFailed extends \RuntimeException {}
