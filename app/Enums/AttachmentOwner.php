<?php

namespace App\Enums;

enum AttachmentOwner: string
{
    case Message = 'message';
    case Post = 'post';
    case Excuse = 'excuse';
    case ReportCard = 'report_card';
}
