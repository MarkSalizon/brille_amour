<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/customer_auth.php';
require_once '../includes/functions.php';
require_once '../includes/payments.php';
require_once '../includes/paymongo.php';
require_once '../includes/payment_receipts.php';
require_once '../includes/innovation.php';
startCustomerSession();
ensureCustomerAuthSchema($conn); ensureCheckoutPaymentSchema($conn); ensurePaymongoSchema($conn); ensurePaymentReceiptSchema($conn); ensureInnovationSchema($conn);
$customer=loadSignedInCustomer($conn); if(!$customer){http_response_code(403);exit('Login required');}
$orderNumber=trim((string)($_GET['order']??''));
$stmt=$conn->prepare("SELECT o.id,o.order_number,o.customer_id,o.customer_name,o.total_amount,o.delivery_fee,o.balance,o.fulfillment_method,o.delivery_address,o.due_date,o.pickup_time,p.id payment_id,p.reference_number,p.amount,p.payment_method,p.payment_method_other,p.created_at,g.paid_payment_id,g.payment_intent_id,g.livemode FROM orders o JOIN checkout_payment_orders po ON po.order_id=o.id JOIN checkout_payments p ON p.id=po.payment_id JOIN paymongo_checkouts g ON g.payment_id=p.id WHERE o.order_number=? AND o.customer_id=? AND g.state IN ('paid','test_paid') ORDER BY p.id DESC LIMIT 1");
$cid=(int)$customer['id'];$stmt->bind_param('si',$orderNumber,$cid);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$r){http_response_code(404);exit('Receipt not found');}
$rec=ensureReceiptForPayment($conn,(int)$r['payment_id']); if(!$rec){http_response_code(404);exit('Receipt not found');}
$business=paymentReceiptBusinessDetails();$method=paymentMethodLabel($r);$status=paymentReceiptStatusLabel((string)$rec['receipt_status']);$tx=trim((string)($r['paid_payment_id']?:$r['payment_intent_id']));
function pdfEsc(string $s):string{return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s);} function pdfMoney($n):string{return 'PHP '.number_format((float)$n,2);}
$lines=[
$business['name'].' - PAYMENT RECEIPT',$rec['receipt_number'],'Status: '.$status.' | '.$method,'Order: '.$r['order_number'].' | Customer: '.$r['customer_name'],'Date: '.$r['created_at'],'Reference: '.$r['reference_number'],'Amount Paid: '.pdfMoney($r['amount']).' | Total: '.pdfMoney($r['total_amount']).' | Balance: '.pdfMoney($r['balance']),'Fulfillment: '.(strtolower(trim((string)$r['fulfillment_method']))==='delivery'?'Delivery':'Pickup')
]; if(strtolower(trim((string)$r['fulfillment_method']))==='delivery'){ if((float)($r['delivery_fee']??0)>0)$lines[]='Delivery Fee: '.pdfMoney($r['delivery_fee']); $lines[]='Address: '.(trim((string)$r['delivery_address'])!==''?$r['delivery_address']:'Not provided');}else{$lines[]='Pickup: Brille Amour Prints & Crafts';} if(trim((string)$r['due_date'])!=='')$lines[]='Schedule: '.trim((string)$r['due_date']).(trim((string)$r['pickup_time'])!==''?' · '.trim((string)$r['pickup_time']):''); if($tx!=='')$lines[]='PayMongo ID: '.$tx;
$content="BT /F1 12 Tf 48 790 Td ";$first=true;foreach($lines as $i=>$line){if(!$first)$content.="0 -17 Td ";$content.='('.pdfEsc((string)$line).") Tj ";$first=false;}$content.="ET\n";
// Draw a simple machine-readable Code39-style visual barcode from the receipt value.
$value='*'.strtoupper((string)$rec['barcode_value']).'*';$x=48;$y=510;$h=52;$n=1.4;$w=3.4;$gap=1.4;$patterns=['0'=>'nnnwwnwnn','1'=>'wnnwnnnnw','2'=>'nnwwnnnnw','3'=>'wnwwnnnnn','4'=>'nnnwwnnnw','5'=>'wnnwwnnnn','6'=>'nnwwwnnnn','7'=>'nnnwnnwnw','8'=>'wnnwnnwnn','9'=>'nnwwnnwnn','A'=>'wnnnnwnnw','B'=>'nnwnnwnnw','C'=>'wnwnnwnnn','D'=>'nnnnwwnnw','E'=>'wnnnwwnnn','F'=>'nnwnwwnnn','G'=>'nnnnnwwnw','H'=>'wnnnnwwnn','I'=>'nnwnnwwnn','J'=>'nnnnwwwnn','K'=>'wnnnnnnww','L'=>'nnwnnnnww','M'=>'wnwnnnnwn','N'=>'nnnnwnnww','O'=>'wnnnwnnwn','P'=>'nnwnwnnwn','Q'=>'nnnnnnwww','R'=>'wnnnnnwwn','S'=>'nnwnnnwwn','T'=>'nnnnwnwwn','U'=>'wwnnnnnnw','V'=>'nwwnnnnnw','W'=>'wwwnnnnnn','X'=>'nwnnwnnnw','Y'=>'wwnnwnnnn','Z'=>'nwwnwnnnn','-'=>'nwnnnnwnw','*'=>'nwnnwnwnn'];foreach(str_split($value) as $ch){$pat=$patterns[$ch]??$patterns['*'];foreach(str_split($pat) as $i=>$sym){$bw=$sym==='w'?$w:$n;if($i%2===0)$content.=sprintf("%.2f %.2f %.2f %.2f re f\n",$x,$y,$bw,$h);$x+=$bw;}$x+=$gap;}
$content.="BT /F1 9 Tf 48 492 Td (".pdfEsc((string)$rec['barcode_value']).") Tj ET\n";
$objs=[];$objs[]='<< /Type /Catalog /Pages 2 0 R >>';$objs[]='<< /Type /Pages /Kids [3 0 R] /Count 1 >>';$objs[]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';$objs[]='<< /Length '.strlen($content).' >>\nstream\n'.$content.'endstream';$objs[]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
$pdf="%PDF-1.4\n";$offs=[0];foreach($objs as $i=>$obj){$offs[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n{$obj}\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";for($i=1;$i<=count($objs);$i++)$pdf.=sprintf("%010d 00000 n \n",$offs[$i]);$pdf.="trailer << /Size ".(count($objs)+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9_-]/','-',(string)$rec['receipt_number']).'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;
