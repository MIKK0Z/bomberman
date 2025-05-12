<?php

declare(strict_types=1);

enum WebSocketDecodeError {
    case ClientDisconnect;
    case InsufficientData;
    case UnmaskedFrame;
    case UnsupportedOpcode;
    case FragmentedMessage;
    case ContinuationFrameOutOfOrder;
}
