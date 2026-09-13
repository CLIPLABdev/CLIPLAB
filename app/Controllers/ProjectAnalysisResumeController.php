<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class ProjectAnalysisResumeController
{
    /** @param callable(int,int,?int): ?string $resume */
    public function __construct(private $resume, private ?ErrorHandler $errors = null)
    {
        $this->errors ??= new ErrorHandler();
    }

    public function store(Request $request, array $parameters): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) return Response::redirect('/login');
        $rawId = (string) ($parameters['id'] ?? '');
        $id = (int) $rawId;
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $rawId) !== 1 || $id < 1 || (string) $id !== $rawId) {
            return $this->errors->renderStatus(404);
        }
        $expectedRefundId = null;
        if ($request->hasInput('expected_refund_id')) {
            $rawRefund = $request->input('expected_refund_id');
            if (!is_string($rawRefund) || preg_match('/\A[1-9][0-9]{0,18}\z/D',$rawRefund) !== 1
                || (string)(int)$rawRefund !== $rawRefund) return $this->errors->renderStatus(404);
            $expectedRefundId = (int)$rawRefund;
        }
        try {
            $status = ($this->resume)($id, $userId, $expectedRefundId);
            if ($status === null) return $this->errors->renderStatus(404);
            $message = match ($status) {
                'ai_queued' => 'Análise solicitada. Acompanhe o andamento deste projeto.',
                'awaiting_credits' => 'Seu saldo ainda é insuficiente. Adicione créditos e tente retomar a análise.',
                'insufficient_credits' => 'Seu saldo é insuficiente para tentar novamente. Adicione créditos e retome a análise.',
                'recovery_stale' => 'Esta tentativa já foi utilizada. Atualize a página para conferir o estado atual.',
                'recovery_unavailable' => 'Este projeto ainda não pode ser retomado. Atualize a página e confira o andamento.',
                'recovery_quota_blocked' => 'O plano atual não permite retomar esta análise. Confira os limites da conta.',
                'source_unavailable' => 'A origem deste vídeo não está pronta para análise.',
                'failed' => 'Não foi possível retomar a análise. Confira a mensagem do projeto e os limites do seu plano.',
                default => 'Este projeto não está aguardando créditos. Confira o andamento atual.',
            };
        } catch (\Throwable) {
            $message = 'Não foi possível retomar a análise agora. Tente novamente em instantes.';
        }
        Session::flash('analysis_resume_feedback', $message);
        return Response::redirect('/projetos/' . $id);
    }
}
