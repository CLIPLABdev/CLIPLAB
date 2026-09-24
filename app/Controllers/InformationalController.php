<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\View;

/** Public product information: deliberately independent of account data and configuration. */
final class InformationalController
{
    public function __construct(private View $view)
    {
    }

    public function privacy(): Response
    {
        return $this->view->render('informational.page',[
            'page'=>'privacy',
            'title'=>'Privacidade',
            'introduction'=>'Entenda quais dados entram no ClipLab, o que é enviado para serviços externos e quais controles estão disponíveis.',
            'sections'=>[
                ['id'=>'dados','title'=>'Dados usados para operar sua conta','paragraphs'=>[
                    'O ClipLab armazena dados de cadastro, como nome e e-mail, senha em formato de hash, informações do plano, saldo e movimentações de créditos. Projetos incluem títulos, vídeos, características técnicas da mídia, sugestões de cortes, legendas e configurações de edição.',
                    'A sessão do navegador mantém sua autenticação e protege os formulários. Registros de processamento, falhas e ações administrativas ajudam a operar o serviço. O provedor de hospedagem também pode manter seus próprios registros de acesso.',
                ]],
                ['id'=>'gemini','title'=>'Vídeo e áudio enviados ao Gemini','paragraphs'=>[
                    'A análise envia o vídeo original ao Gemini, serviço de inteligência artificial do Google, para sugerir trechos. Quando você escolhe legendas automáticas, o áudio do intervalo selecionado também é enviado ao Gemini para transcrição. Esses recursos não acontecem apenas no seu dispositivo.',
                    'O tratamento e a retenção pelo Google dependem dos termos vigentes e da modalidade da conta usada pelo operador. Esta página não garante que o provedor deixe de usar os dados para melhoria de produtos. Não envie material confidencial, sensível ou de terceiros sem as autorizações necessárias.',
                    'O processamento tenta remover o arquivo remoto de análise ao concluir ou encerrar o trabalho. Essa tentativa não é garantia de eliminação imediata nem substitui as regras de retenção do provedor.',
                ]],
                ['id'=>'midia-privada','title'=>'Mídia privada e acesso','paragraphs'=>[
                    'Os originais, vídeos exportados e miniaturas ficam em armazenamento privado. As rotas de prévia e download verificam a sessão, o proprietário e a análise atual. Não há galeria pública nem publicação automática em redes sociais.',
                    'Privado não significa processamento exclusivamente local: o envio ao Gemini descrito acima continua necessário para os recursos de IA. A operação da infraestrutura exige acesso técnico controlado. Quem baixa ou publica um arquivo passa a controlar essa cópia fora do ClipLab.',
                ]],
                ['id'=>'enquadramento','title'=>'MediaPipe, processamento local e consentimento','paragraphs'=>[
                    'O enquadramento inteligente usa MediaPipe para detectar rostos nos frames processados no seu dispositivo. Para aplicar o recorte, o ClipLab envia ao servidor apenas tempos e posições de enquadramento, não imagens faciais separadas, detecções brutas, embeddings ou identidade.',
                    'O SDK pode enviar ao Google métricas técnicas de desempenho e uso. Por isso, a ativação automática exige consentimento afirmativo na seção de privacidade do projeto ou editor. Abrir esta página não carrega o SDK nem ativa essa análise.',
                    'Você pode revogar esse consentimento no projeto ou editor. A revogação impede novas análises automáticas; não desfaz versões já geradas nem apaga os registros anteriores de consentimento. Os modos centralizado e manual continuam disponíveis sem essa autorização.',
                ]],
                ['id'=>'retencao','title'=>'Retenção, limpeza e suas cópias','paragraphs'=>[
                    'Esta versão não define um prazo automático para excluir projetos, vídeos originais, exportações, registros da conta ou backups. A política de retenção e o atendimento a pedidos de acesso ou exclusão dependem do responsável pela operação.',
                    'A limpeza técnica de temporários e artefatos abandonados não equivale à exclusão da sua conta ou de todo o conteúdo. Revogar o consentimento MediaPipe ou sair da conta também não apaga esses dados. Mantenha cópias próprias dos arquivos importantes.',
                ]],
                ['id'=>'responsavel','title'=>'Responsável e atendimento','paragraphs'=>[
                    'A identificação do responsável pela operação, o canal de contato e os procedimentos para solicitações de dados precisam ser informados antes do lançamento público. Nenhuma identidade ou forma de contato é presumida nesta página.',
                    'Este texto descreve o funcionamento da versão atual. Não representa avaliação jurídica nem declaração de conformidade legal.',
                ]],
            ],
        ]);
    }

    public function terms(): Response
    {
        return $this->view->render('informational.page',[
            'page'=>'terms',
            'title'=>'Termos de uso',
            'introduction'=>'Uma explicação direta das condições operacionais, dos limites do produto e das escolhas que continuam sob seu controle.',
            'sections'=>[
                ['id'=>'seu-conteudo','title'=>'Sua conta e seu conteúdo','paragraphs'=>[
                    'Use uma conta própria e mantenha suas credenciais protegidas. Envie apenas material que você possa usar e autorizar a processar, incluindo a imagem, voz, música e demais elementos de terceiros. Não use o serviço para conteúdo ilícito, fraude ou para violar direitos de outras pessoas.',
                    'Você escolhe o material, revisa os resultados e decide se e onde publicar. O ClipLab não publica em redes sociais por você.',
                ]],
                ['id'=>'ia','title'=>'IA exige revisão','paragraphs'=>[
                    'A análise do vídeo e a transcrição automática de áudio usam Gemini. Leia a página de Privacidade antes de enviar conteúdo. O enquadramento inteligente MediaPipe é um recurso separado e só pode ser ativado após o consentimento correspondente.',
                    'Sugestões, notas de potencial, cortes e legendas podem conter erros ou perder contexto. Revise os tempos, o texto, o enquadramento e o vídeo final. Estilos chamados viral, highlight ou karaoke descrevem apresentação visual, não resultados garantidos.',
                    'Não há garantia de viralização, alcance, engajamento, receita ou precisão integral da IA. A prévia do editor é aproximada; o arquivo exportado é o resultado a conferir antes de publicar.',
                ]],
                ['id'=>'limites','title'=>'Planos, limites e créditos','paragraphs'=>[
                    'Os limites do plano, o saldo de créditos, o uso de minutos e o espaço ocupado aparecem na área da conta. Os limites efetivos também dependem da aplicação e da hospedagem. Ultrapassar uma quota pode bloquear novo uso; isso não aciona a exclusão automática dos arquivos existentes.',
                    'A análise reserva créditos antes de processar. O consumo é registrado uma vez quando há sugestões válidas; falhas terminais cobertas pelo processamento devolvem a reserva. Confira as movimentações no extrato. Renderizar uma versão não cria uma segunda reserva de análise, mas continua sujeito aos limites de uso e espaço.',
                    'Planos e créditos são administrados pelo operador. Nesta versão não há checkout, cobrança automática nem contratação de plano pago pelo próprio usuário. Preços exibidos não confirmam pagamento ou ativação de recursos. Condições comerciais adicionais precisam ser informadas pelo operador.',
                ]],
                ['id'=>'exportacoes','title'=>'Processamento e novas versões','paragraphs'=>[
                    'Ao criar um projeto, você pode escolher a geração automática de até três cortes sugeridos. Também pode ajustar um corte no editor e solicitar uma nova exportação. Editar um clipe concluído cria outra versão e preserva o arquivo anterior, em vez de sobrescrevê-lo.',
                    'Os trabalhos passam por uma fila e continuam sem a página aberta. Enviar uma solicitação não significa que o vídeo já está pronto. O download fica disponível quando o processamento conclui; falhas de mídia, serviços externos, capacidade ou quota podem impedir a conclusão.',
                    'Não há promessa de prazo fixo de processamento ou disponibilidade contínua. A qualidade e a compatibilidade dependem do material enviado e dos recursos disponíveis.',
                ]],
                ['id'=>'armazenamento','title'=>'Armazenamento e atendimento','paragraphs'=>[
                    'Os arquivos permanecem privados dentro da aplicação, com acesso autenticado. Esta versão não oferece prazo automático de retenção nem substitui seu backup. Mantenha cópias dos originais e das exportações importantes.',
                    'Pedidos relacionados à conta, aos dados ou às condições comerciais dependem do responsável pela operação. Sua identificação, canal de atendimento e regras complementares precisam ser definidos antes do lançamento público.',
                ]],
                ['id'=>'sobre-termos','title'=>'Sobre estas informações','paragraphs'=>[
                    'Estas condições descrevem a versão atual do produto e devem acompanhar suas mudanças. A leitura desta página não registra consentimento para o MediaPipe nem ativa recursos ou cobranças.',
                    'Este conteúdo informativo não declara conformidade legal e não substitui a revisão jurídica e operacional necessária antes de oferecer o serviço ao público.',
                ]],
            ],
        ]);
    }
}
